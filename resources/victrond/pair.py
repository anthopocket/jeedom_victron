#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Appairage par jeton MQTT avec un GX (Venus OS récent).

Le GX doit être en mode appairage :
  Paramètres > Intégrations > Appareils MQTT > Mode appairage
  (ou double appui rapide sur le bouton des GX sans écran).

Usage : pair.py --host <ip> --id <identifiant VRM>
Sortie (JSON sur stdout) : {"ok": true, "username": "...", "password": "..."}
                       ou {"ok": false, "error": "..."}
"""

import argparse
import asyncio
import json
import re

from victron_mqtt import PairingError, request_pairing_token


async def run(host: str, device_id: str) -> dict:
    try:
        token = await request_pairing_token(host, device_id, role="jeedom")
    except PairingError as err:
        return {"ok": False, "error": f"Appairage refusé par le GX ({err}). Le mode appairage est-il actif ?"}
    except Exception as err:  # noqa: BLE001
        return {"ok": False, "error": f"GX injoignable en HTTPS sur {host} : {err}"}
    return {"ok": True, "username": token.token_name, "password": token.password}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--id", default="jeedom")
    args = parser.parse_args()
    device_id = re.sub(r"[^A-Za-z0-9]", "", args.id) or "jeedom"
    print(json.dumps(asyncio.run(run(args.host, device_id))))


if __name__ == "__main__":
    main()
