#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
victrond - Démon Jeedom pour le plugin Victron GX.

Équivalent de l'intégration Home Assistant « victron_gx » :
il s'appuie sur la même librairie (victron-mqtt) qui se connecte directement
au broker MQTT du GX (Cerbo, Venus GX, Ekrano...), découvre automatiquement
les appareils (VE.Bus, chargeurs solaires, batteries, compteurs, ESS, DESS...)
et leurs métriques, puis les remonte à Jeedom.

Flux :
  GX (MQTT)  --victron-mqtt-->  victrond  --HTTP POST JSON-->  jeeVictron.php
  Jeedom  --socket TCP JSON-->  victrond  --metric.set()-->  GX
"""

from __future__ import annotations

import argparse
import asyncio
import datetime as dt
import json
import logging
import os
import signal
import sys
import time
from typing import Any

import aiohttp
from victron_mqtt import (
    UPDATE_FREQUENCY_AUTO,
    UPDATE_FREQUENCY_AUTO_UNTHROTTLED,
    AuthenticationError,
    CannotConnectError,
    Device,
    GenericOnOff,
    GpsLocation,
    Hub,
    Metric,
    MetricKind,
    OperationMode,
    VictronEnum,
    WritableMetric,
)

_LOGGER = logging.getLogger("victrond")

# Métriques que HA désactive par défaut (trop nombreuses / diagnostic) :
# on les crée masquées dans Jeedom.
HIDDEN_BY_DEFAULT = {
    "system_heartbeat",
    "platform_device_reboot",
    "solarcharger_device_off_reason",
    "solarcharger_tracker_{tracker}_power",
    "solarcharger_tracker_{tracker}_voltage",
    "solarcharger_tracker_{tracker}_current",
    "solarcharger_tracker_{tracker}_operation_mode",
    "solarcharger_tracker_{tracker}_name",
    "solarcharger_tracker_{tracker}_max_power_today",
    "solarcharger_tracker_{tracker}_max_voltage_today",
    "solarcharger_tracker_{tracker}_yield_today",
    "multi_mppt_{mppt_id}_yield_today",
    "multi_mppt_{mppt_id}_yield_yesterday",
    "multi_mppt_{mpptnumber}_state",
    "multi_mppt_{mpptnumber}_power",
    "multi_mppt_{mpptnumber}_voltage",
    "multi_mppt_{mpptnumber}_current",
}

# Types de métriques gérés (mêmes plateformes que HA)
HANDLED_KINDS = {
    MetricKind.SENSOR,
    MetricKind.BINARY_SENSOR,
    MetricKind.SWITCH,
    MetricKind.NUMBER,
    MetricKind.SELECT,
    MetricKind.BUTTON,
    MetricKind.TIME,
    MetricKind.DEVICE_TRACKER,
}
BINARY_KINDS = {MetricKind.BINARY_SENSOR, MetricKind.SWITCH}

# Taille max d'un lot de définitions envoyé à Jeedom (création de commandes)
DEFS_CHUNK = 80


# ---------------------------------------------------------------------------
# Conversions
# ---------------------------------------------------------------------------
def minutes_to_hhmm(value: Any) -> str | None:
    if value is None:
        return None
    total = int(value)
    return f"{(total // 60) % 24:02d}:{total % 60:02d}"


def hhmm_to_minutes(value: Any) -> int:
    """Accepte '06:30', '6h30', '630', '0630' ou un nombre de minutes."""
    text = str(value).strip().lower().replace("h", ":")
    if ":" in text:
        hours, _, minutes = text.partition(":")
        h, m = int(hours or 0), int(minutes or 0)
    elif text.isdigit() and len(text) in (3, 4):
        h, m = int(text[:-2]), int(text[-2:])
    else:
        return int(float(text))
    if not (0 <= h <= 23 and 0 <= m <= 59):
        raise ValueError(f"Heure invalide : {value}")
    return h * 60 + m


def enum_labels(metric: Metric) -> list[dict[str, str]]:
    """Liste [{id, label}] des valeurs possibles d'une métrique enum."""
    ids = metric.enum_values or []
    enum_cls = getattr(getattr(metric, "_descriptor", None), "enum", None)
    out = []
    for enum_id in ids:
        label = enum_id
        if enum_cls is not None:
            try:
                member = enum_cls.from_id_or_string(enum_id)
                if member is not None:
                    label = member.string
            except Exception:  # noqa: BLE001
                pass
        # ';' et '|' sont les séparateurs de listValue Jeedom
        if ";" in label or "|" in label:
            label = enum_id
        out.append({"id": enum_id, "label": label})
    return out


def to_jeedom(metric: Metric, value: Any) -> Any:
    """Convertit une valeur victron-mqtt en valeur de commande info Jeedom."""
    if value is None:
        return None
    kind = metric.metric_kind
    if kind in BINARY_KINDS:
        if isinstance(value, VictronEnum):
            if value.id == "on":
                return 1
            if value.id == "off":
                return 0
            return None
        if isinstance(value, bool):
            return int(value)
        return None
    if kind is MetricKind.TIME:
        return minutes_to_hhmm(value)
    if isinstance(value, VictronEnum):
        return value.string
    if isinstance(value, GpsLocation):
        return f"{value.latitude},{value.longitude}"
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, float):
        prec = metric.precision
        return round(value, prec) if prec is not None else value
    if isinstance(value, dt.datetime):
        return value.astimezone().strftime("%Y-%m-%d %H:%M:%S")
    if isinstance(value, (list, tuple, set)):
        return ", ".join(v.string if isinstance(v, VictronEnum) else str(v) for v in value)
    return value


# ---------------------------------------------------------------------------
# Démon
# ---------------------------------------------------------------------------
class VictronDaemon:
    def __init__(self, args: argparse.Namespace) -> None:
        self.args = args
        self.hub: Hub | None = None
        self.installation_id: str | None = None
        self.devices: dict[str, dict[str, Any]] = {}
        self.metrics: dict[tuple[str, str], Metric] = {}
        self.defs: dict[tuple[str, str], dict[str, Any]] = {}
        # Files d'attente vers Jeedom
        self.pending_devices: dict[str, dict[str, Any]] = {}
        self.pending_defs: dict[tuple[str, str], dict[str, Any]] = {}
        self.pending_values: dict[str, dict[str, Any]] = {}
        self.pending_messages: list[dict[str, Any]] = []
        self.status: dict[str, Any] = {"connected": False}
        self.status_dirty = True
        self.stop_event = asyncio.Event()
        self.session: aiohttp.ClientSession | None = None

    # ----- Callbacks victron-mqtt (exécutés dans la boucle asyncio) -----
    def _device_info(self, device: Device) -> dict[str, Any]:
        info = {
            "uid": device.unique_id,
            "name": device.name,
            "type": device.device_type.string if device.device_type else "",
            "type_code": getattr(device.device_type, "code", ""),
            "model": device.model or "",
            "manufacturer": device.manufacturer or "Victron Energy",
            "serial": device.serial_number or "",
            "firmware": device.firmware_version or "",
            "product_id": device.product_id if device.product_id is not None else "",
            "device_id": device.device_id,
            "custom_name": device.custom_name or "",
            "parent": device.parent_device.unique_id if device.parent_device else "",
        }
        return info

    def on_new_metric(self, hub: Hub, device: Device, metric: Metric) -> None:
        try:
            self._register_metric(device, metric)
        except Exception:  # noqa: BLE001
            _LOGGER.exception("Erreur à l'enregistrement de la métrique %s", metric)

    def _register_metric(self, device: Device, metric: Metric) -> None:
        if metric.metric_kind not in HANDLED_KINDS:
            _LOGGER.debug("Métrique ignorée (%s) : %s", metric.metric_kind, metric.unique_id)
            return
        # Parents d'abord (ex. multi -> trackers)
        chain = []
        dev: Device | None = device
        while dev is not None:
            chain.append(dev)
            dev = dev.parent_device
        for d in reversed(chain):
            info = self._device_info(d)
            if self.devices.get(d.unique_id) != info:
                self.devices[d.unique_id] = info
                self.pending_devices[d.unique_id] = info

        key = (device.unique_id, metric.short_id)
        self.metrics[key] = metric
        definition = {
            "device": device.unique_id,
            "id": metric.short_id,
            "generic_id": metric.generic_short_id,
            "name": metric.name,
            "kind": metric.metric_kind.value,
            "type": metric.metric_type.value,
            "nature": metric.metric_nature.value,
            "unit": metric.unit_of_measurement or "",
            "value_type": getattr(getattr(getattr(metric, "_descriptor", None), "value_type", None), "value", ""),
            "precision": metric.precision,
            "main": bool(metric.main_topic),
            "hidden": metric.generic_short_id in HIDDEN_BY_DEFAULT,
        }
        if isinstance(metric, WritableMetric):
            definition["min"] = metric.min_value
            definition["max"] = metric.max_value
            definition["step"] = metric.step
        if metric.metric_kind in (MetricKind.SELECT, MetricKind.SENSOR) and metric.enum_values:
            definition["options"] = enum_labels(metric)
        self.defs[key] = definition
        self.pending_defs[key] = definition
        _LOGGER.debug("Nouvelle métrique %s/%s (%s)", device.unique_id, metric.short_id, metric.metric_kind.value)

        metric.on_update = self._make_update_cb(device.unique_id)
        self._queue_value(device.unique_id, metric, metric.value)

    def _make_update_cb(self, device_uid: str):
        def _cb(metric: Metric, value: Any) -> None:
            if not metric.available:
                return
            self._queue_value(device_uid, metric, value)

        return _cb

    def _queue_value(self, device_uid: str, metric: Metric, value: Any) -> None:
        if metric.metric_kind is MetricKind.BUTTON:
            return
        try:
            converted = to_jeedom(metric, value)
        except Exception:  # noqa: BLE001
            _LOGGER.exception("Conversion impossible pour %s=%r", metric.unique_id, value)
            return
        if converted is None:
            return
        self.pending_values.setdefault(device_uid, {})[metric.short_id] = converted

    # ----- Envoi vers Jeedom -----
    async def _post(self, payload: dict[str, Any]) -> bool:
        assert self.session is not None
        try:
            async with self.session.post(
                self.args.callback,
                params={"apikey": self.args.apikey},
                json=payload,
                timeout=aiohttp.ClientTimeout(total=180),
            ) as resp:
                text = await resp.text()
                if resp.status != 200:
                    _LOGGER.error("Callback Jeedom HTTP %s : %s", resp.status, text[:300])
                    return False
                return True
        except Exception as err:  # noqa: BLE001
            _LOGGER.error("Échec d'envoi vers Jeedom : %s", err)
            return False

    async def sender_loop(self) -> None:
        cycle = max(0.2, float(self.args.cycle))
        while not self.stop_event.is_set():
            await asyncio.sleep(cycle)
            try:
                await self.flush()
            except Exception:  # noqa: BLE001
                _LOGGER.exception("Erreur dans la boucle d'envoi")

    async def flush(self) -> None:
        # 1) équipements + définitions (par lots), 2) valeurs
        while self.pending_devices or self.pending_defs:
            devices, self.pending_devices = self.pending_devices, {}
            keys = list(self.pending_defs.keys())[:DEFS_CHUNK]
            defs = [self.pending_defs.pop(k) for k in keys]
            payload: dict[str, Any] = {"installation_id": self.installation_id}
            if devices:
                payload["devices"] = list(devices.values())
            if defs:
                payload["metrics"] = defs
            if not await self._post(payload):
                # on remet en file pour le prochain cycle
                self.pending_devices.update(devices)
                for d in defs:
                    self.pending_defs.setdefault((d["device"], d["id"]), d)
                return

        payload = {}
        if self.pending_values:
            payload["values"], self.pending_values = self.pending_values, {}
        if self.pending_messages:
            payload["messages"], self.pending_messages = self.pending_messages, []
        if self.status_dirty:
            payload["status"] = dict(self.status)
            self.status_dirty = False
        if payload:
            payload["installation_id"] = self.installation_id
            if not await self._post(payload):
                # on garde les dernières valeurs (sans écraser plus récentes)
                for dev, vals in payload.get("values", {}).items():
                    merged = dict(vals)
                    merged.update(self.pending_values.get(dev, {}))
                    self.pending_values[dev] = merged
                if "status" in payload:
                    self.status_dirty = True

    def set_status(self, **kwargs: Any) -> None:
        changed = any(self.status.get(k) != v for k, v in kwargs.items())
        self.status.update(kwargs)
        if changed:
            self.status_dirty = True

    def message(self, level: str, text: str) -> None:
        log = _LOGGER.error if level == "error" else _LOGGER.warning
        log(text)
        self.pending_messages.append({"level": level, "text": text})

    # ----- Connexion au GX -----
    def _update_frequency(self) -> Any:
        freq = str(self.args.frequency).strip()
        if freq in ("auto", ""):
            return UPDATE_FREQUENCY_AUTO
        if freq == "auto_unthrottled":
            return UPDATE_FREQUENCY_AUTO_UNTHROTTLED
        if freq == "change":
            return None
        return int(freq)

    async def hub_loop(self) -> None:
        delay = 10
        while not self.stop_event.is_set():
            if self.hub is None:
                hub = Hub(
                    host=self.args.host,
                    port=int(self.args.port),
                    username=os.environ.get("VICTRON_USERNAME") or None,
                    password=os.environ.get("VICTRON_PASSWORD") or None,
                    use_ssl=self.args.ssl == "1",
                    installation_id=self.args.installation_id or None,
                    operation_mode=OperationMode.READ_ONLY if self.args.readonly == "1" else OperationMode.FULL,
                    update_frequency_seconds=self._update_frequency(),
                )
                hub.on_new_metric = self.on_new_metric
                try:
                    _LOGGER.info("Connexion au GX %s:%s (ssl=%s)", self.args.host, self.args.port, self.args.ssl)
                    await hub.connect()
                except AuthenticationError as err:
                    self.set_status(connected=False, error="auth")
                    self.message("error", f"Victron : authentification refusée par le GX ({err}). Vérifiez le mot de passe / l'appairage.")
                    delay = 300
                except CannotConnectError as err:
                    self.set_status(connected=False, error="connect")
                    _LOGGER.warning("Connexion au GX impossible : %s (nouvel essai dans %ss)", err, delay)
                except Exception as err:  # noqa: BLE001
                    self.set_status(connected=False, error="unknown")
                    _LOGGER.exception("Erreur inattendue à la connexion : %s", err)
                else:
                    self.hub = hub
                    self.installation_id = hub.installation_id
                    self.set_status(connected=True, error="", installation_id=hub.installation_id or "",
                                    model=hub.model_name or "", since=int(time.time()))
                    _LOGGER.info("Connecté au GX, installation %s", hub.installation_id)
                    delay = 10
                    continue
                try:
                    await asyncio.wait_for(self.stop_event.wait(), timeout=delay)
                except asyncio.TimeoutError:
                    pass
                delay = min(delay * 2, 300) if delay < 300 else delay
            else:
                # Après la 1re connexion, paho se reconnecte tout seul
                self.set_status(connected=bool(self.hub.connected))
                try:
                    await asyncio.wait_for(self.stop_event.wait(), timeout=15)
                except asyncio.TimeoutError:
                    pass

    # ----- Commandes venant de Jeedom -----
    async def handle_socket(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            raw = await asyncio.wait_for(reader.read(), timeout=10)
            data = json.loads(raw.decode("utf-8"))
            if data.get("apikey") != self.args.apikey:
                _LOGGER.error("Clé API invalide reçue sur le socket")
                return
            _LOGGER.debug("Reçu de Jeedom : %s", {k: v for k, v in data.items() if k != "apikey"})
            await self.process(data)
        except Exception:  # noqa: BLE001
            _LOGGER.exception("Erreur de traitement du message socket")
        finally:
            writer.close()

    async def process(self, data: dict[str, Any]) -> None:
        action = data.get("action")
        if action == "set":
            self.do_set(str(data.get("device")), str(data.get("metric")), data.get("value"))
        elif action == "sync":
            device = data.get("device")
            for key, definition in self.defs.items():
                if device and key[0] != device:
                    continue
                self.pending_devices[key[0]] = self.devices[key[0]]
                self.pending_defs[key] = definition
                metric = self.metrics[key]
                if metric.available:
                    self._queue_value(key[0], metric, metric.value)
            self.status_dirty = True
            _LOGGER.info("Resynchronisation demandée (%s)", device or "tout")
        elif action == "stop":
            self.stop_event.set()
        else:
            _LOGGER.warning("Action inconnue : %s", action)

    def do_set(self, device_uid: str, short_id: str, value: Any) -> None:
        metric = self.metrics.get((device_uid, short_id))
        if metric is None or not isinstance(metric, WritableMetric):
            self.message("warning", f"Victron : métrique {device_uid}/{short_id} inconnue ou non modifiable")
            return
        if self.hub is None or not self.hub.connected:
            self.message("warning", f"Victron : GX non connecté, commande {short_id} ignorée")
            return
        kind = metric.metric_kind
        try:
            if kind is MetricKind.BUTTON:
                metric.set(GenericOnOff.ON)
            elif kind is MetricKind.SWITCH:
                on = str(value).lower() in ("1", "on", "true")
                metric.set("on" if on else "off")
            elif kind is MetricKind.NUMBER:
                num = float(str(value).replace(",", "."))
                if num.is_integer():
                    num = int(num)
                metric.set(num)
            elif kind is MetricKind.SELECT:
                metric.set(str(value))
            elif kind is MetricKind.TIME:
                metric.set(hhmm_to_minutes(value))
            else:
                metric.set(value)
            _LOGGER.info("Écriture %s/%s = %s", device_uid, short_id, value)
        except Exception as err:  # noqa: BLE001
            self.message("error", f"Victron : impossible d'écrire {metric.name} = {value} ({err})")

    # ----- Cycle de vie -----
    async def run(self) -> None:
        self.session = aiohttp.ClientSession()
        server = await asyncio.start_server(self.handle_socket, "127.0.0.1", int(self.args.socketport))
        _LOGGER.info("Socket en écoute sur 127.0.0.1:%s", self.args.socketport)
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGTERM, signal.SIGINT):
            loop.add_signal_handler(sig, self.stop_event.set)
        tasks = [asyncio.create_task(self.hub_loop()), asyncio.create_task(self.sender_loop())]
        await self.stop_event.wait()
        _LOGGER.info("Arrêt du démon")
        server.close()
        for t in tasks:
            t.cancel()
        if self.hub is not None:
            try:
                await self.hub.disconnect()
            except Exception:  # noqa: BLE001
                pass
        try:
            self.set_status(connected=False)
            await asyncio.wait_for(self.flush(), timeout=5)
        except Exception:  # noqa: BLE001
            pass
        await self.session.close()


def main() -> None:
    parser = argparse.ArgumentParser(description="Démon Victron GX pour Jeedom")
    parser.add_argument("--loglevel", default="info")
    parser.add_argument("--socketport", default="55090")
    parser.add_argument("--callback", required=True)
    parser.add_argument("--apikey", required=True)
    parser.add_argument("--pid", default="/tmp/jeedom/victron/daemon.pid")
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", default="1883")
    parser.add_argument("--ssl", default="0")
    parser.add_argument("--installation_id", default="")
    parser.add_argument("--frequency", default="auto")
    parser.add_argument("--readonly", default="0")
    parser.add_argument("--cycle", default="1")
    args = parser.parse_args()

    level = {"debug": logging.DEBUG, "info": logging.INFO, "notice": logging.INFO,
             "warning": logging.WARNING, "error": logging.ERROR}.get(args.loglevel, logging.INFO)
    logging.basicConfig(level=level, format="[%(asctime)s][%(levelname)s] %(name)s : %(message)s",
                        datefmt="%Y-%m-%d %H:%M:%S", stream=sys.stdout)
    # La librairie est très bavarde en debug : on la limite à info sauf en debug
    logging.getLogger("victron_mqtt").setLevel(level if level == logging.DEBUG else max(level, logging.WARNING))

    os.makedirs(os.path.dirname(args.pid), exist_ok=True)
    with open(args.pid, "w", encoding="utf-8") as fh:
        fh.write(str(os.getpid()))
    _LOGGER.info("Démarrage victrond (pid %s)", os.getpid())
    try:
        asyncio.run(VictronDaemon(args).run())
    finally:
        try:
            os.remove(args.pid)
        except OSError:
            pass
        _LOGGER.info("Démon arrêté")


if __name__ == "__main__":
    main()
