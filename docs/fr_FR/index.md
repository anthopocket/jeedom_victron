# Plugin Victron GX pour Jeedom

Ce plugin intègre une installation **Victron Energy** dans Jeedom en local, via le broker MQTT intégré au GX (Cerbo GX, Venus GX, Ekrano GX, Color Control, Raspberry Pi sous Venus OS).

Il est l'équivalent de l'intégration Home Assistant **`victron_gx`** : il utilise la même librairie Python (`victron-mqtt`, version 2026.9.5), la même découverte automatique et les mêmes types de commandes.

- **Lecture** : onduleurs/chargeurs VE.Bus, Multi RS, MPPT, onduleurs PV, batteries et BMS, compteurs réseau et de consommation, ESS, Dynamic ESS, générateur, bornes EVCS, réservoirs, sondes, GPS…
- **Pilotage** : SOC mini ESS, consigne réseau, limites de courant AC, mode du Multi, plannings de charge, relais et sorties commutables, générateur…
- **Temps réel** : les valeurs sont poussées par le GX, il n'y a pas d'interrogation périodique.
- **Pas besoin de MQTT Manager ni de jMQTT** : le démon se connecte directement au GX.

---

## Sommaire

1. [Prérequis](#1-prérequis)
2. [Préparer le GX](#2-préparer-le-gx)
3. [Installation](#3-installation)
4. [Configuration du plugin](#4-configuration-du-plugin)
5. [Équipements](#5-équipements)
6. [Commandes : principes](#6-commandes--principes)
7. [Alarmes](#7-alarmes)
8. [Exemples d'utilisation](#8-exemples-dutilisation)
9. [Logs et dépannage](#9-logs-et-dépannage)
10. [Référence complète des commandes](#10-référence-complète-des-commandes)
11. [Annexe : listes de valeurs longues](#11-annexe--listes-de-valeurs-longues)
12. [Changelog](#12-changelog)

---

## 1. Prérequis

| Élément | Exigence |
|---|---|
| Jeedom | 4.2 ou plus |
| Système | Debian 11 ou Debian 12 |
| Python | 3.11 ou plus (géré automatiquement, voir ci-dessous) |
| GX | Venus OS avec le service MQTT activé |
| Réseau | Jeedom doit joindre le GX sur le port 1883 (en clair) ou 8883 (TLS) |

**Python et Debian.** La librairie `victron-mqtt` exige Python 3.11. L'installation des dépendances utilise `dependance.lib` et `pyenv.lib` (NebzHB), comme les autres plugins à démon Python :

- **Debian 12** : le Python 3.11 du système est utilisé directement. L'installation prend une à deux minutes.
- **Debian 11** : le Python du système (3.9) est trop ancien. PyEnv compile alors Python 3.11 dans `/opt/pyenv`, ce qui prend 15 à 30 minutes la première fois. Cette installation est partagée avec les autres plugins qui utilisent PyEnv, et le Python du système n'est pas modifié.
- **Après une migration de Debian 11 vers 12** : relancez l'installation des dépendances. Le venv est recréé sur le Python du système.

---

## 2. Préparer le GX

### 2.1 Activer le MQTT

Sur le GX : **Paramètres → Services → MQTT Access**, à mettre sur **ON**.

> Sur les Venus OS récents, ne choisissez pas « Paired devices only » (appareils appairés uniquement) si vous ne voulez pas utiliser l'appairage par jeton : seules les connexions appairées seraient acceptées.

### 2.2 Profil de sécurité réseau local

Sur le GX : **Paramètres → Général → Network Security Profile** (profil de sécurité réseau local). Ce mot de passe est celui du GX : il sert aussi pour l'interface web et la console à distance.

| Profil sur le GX | Port | SSL | Utilisateur | Mot de passe |
|---|---|---|---|---|
| Non sécurisé / « Non garanti » | 1883 | non | vide | vide |
| Faible (Weak) | 8883 | oui | vide | mot de passe du GX |
| Sécurisé (Secured) | 8883 | oui | vide | mot de passe du GX |
| Appairage par jeton | 8883 | oui | rempli automatiquement | rempli automatiquement |

- **Utilisateur** : le GX n'a pas de compte utilisateur. Laissez le champ vide : le plugin envoie automatiquement un nom, car le protocole MQTT en exige un.
- **Certificat** : le certificat du GX est auto-signé et il n'est pas vérifié.

### 2.3 Appairage par jeton (facultatif)

L'appairage par jeton est **facultatif**. Il sert surtout quand l'accès MQTT du GX est limité aux appareils appairés.

1. Sur le GX, activez le mode appairage : **Paramètres → Intégrations → Appareils MQTT → Mode appairage**. Sur les GX sans écran, faites un double appui rapide sur le bouton.
2. Dans la configuration du plugin, saisissez l'adresse du GX, éventuellement l'ID VRM, puis cliquez sur **Appairer**.
3. L'utilisateur, le mot de passe, le port 8883 et le SSL sont remplis et enregistrés automatiquement. Il ne reste qu'à redémarrer le démon.

---

## 3. Installation

1. Installez le plugin : copiez le dossier `victron` dans `/var/www/html/plugins/` (propriétaire `www-data`), ou passez par l'envoi de fichier de Jeedom.
2. Activez le plugin dans **Plugins → Gestion des plugins**.
3. Installez les **dépendances**. L'avancement est visible dans le log `victron_update`.
4. Renseignez la **configuration** (section 4) et sauvegardez.
5. **Démarrez le démon**. Les équipements apparaissent au fil de la découverte, sous **Plugins → Energie → Victron GX**.

---

## 4. Configuration du plugin

### Connexion au GX

| Champ | Rôle | Valeur par défaut |
|---|---|---|
| Adresse du GX | IP ou nom (`192.168.1.50`, `venus.local`) | — (obligatoire) |
| Port | `1883` en clair, `8883` en TLS | `1883` |
| SSL | Chiffrement TLS (profils Weak/Secured ou jeton) | non |
| Utilisateur | Vide, sauf appairage par jeton | vide |
| Mot de passe | Mot de passe du GX (profils Weak/Secured) | vide |
| Appairage par jeton | ID VRM (facultatif) + bouton **Appairer** | — |
| État | Connecté / Déconnecté / Authentification refusée, ID d'installation, modèle du GX | — |

### Fonctionnement

| Champ | Rôle | Valeur par défaut |
|---|---|---|
| Fréquence de mise à jour | Cadence de remontée des valeurs (voir ci-dessous) | Auto |
| Regroupement des envois (s) | Les valeurs reçues sont envoyées à Jeedom par lots toutes les X secondes | `1` |
| Création automatique des équipements | Crée un équipement Jeedom pour chaque nouvel appareil Victron détecté | oui |
| Objet parent par défaut | Objet dans lequel les nouveaux équipements sont rangés | aucun |
| Historiser automatiquement | Historise, à leur création, les puissances, énergies, SOC, tensions, courants et températures | non |
| Lecture seule | Aucune commande d'écriture vers le GX : les réglages deviennent de simples infos (redémarrer le démon) | non |
| Port socket interne | Port local entre Jeedom et le démon | `55090` |

**Fréquence de mise à jour :**

| Choix | Comportement |
|---|---|
| Auto (recommandé, comme HA) | Puissances, courants et position : au plus toutes les 5 s. SOC et pourcentages : à chaque changement. Le reste : au plus toutes les 30 s |
| Auto sans limite | Comme Auto, mais puissances et courants à chaque changement (plus de charge sur Jeedom) |
| À chaque changement | Toute valeur qui change est remontée immédiatement |
| 10 / 30 / 60 s max | Chaque valeur est remontée au plus une fois par intervalle |

---

## 5. Équipements

- **Un équipement Jeedom par appareil Victron**, créé automatiquement. L'identifiant logique est `<type>_<instance>`, par exemple `battery_512`, `vebus_276`, `solarcharger_279` ou `system_0`.
- **Nom** : il est fourni par le GX, par exemple « SmartShunt (ID: 512) », « MultiPlus-II (ID: 276) » ou « Victron Venus » pour le système. Si le nom existe déjà dans l'objet, l'identifiant est ajouté au nom. Vous pouvez renommer librement.
- **Réglages système** : ESS, DVCC, Dynamic ESS et relais du GX sont rattachés à l'équipement **Système** (`system_0`).
- **Sous-équipements** : chaque sortie commutable (relais du GX, sorties Energy Solutions) devient un équipement séparé, par exemple `system_0_output_relay_1`.
- **Onglet Équipement** : il affiche le type, le modèle, le fabricant, le numéro de série, le firmware, le Product ID, l'instance et l'appareil parent.
- **Bouton « Recréer les commandes »** (sur la fiche d'un équipement) : il demande au démon de renvoyer les commandes de cet appareil. Les commandes supprimées sont recréées et les autres sont conservées.
- **Bouton « Synchroniser »** (sur la page du plugin) : il fait la même chose pour tous les appareils.
- **Équipement désactivé** : ses commandes ne sont plus créées ni mises à jour. Après l'avoir réactivé, cliquez sur « Recréer les commandes ».

---

## 6. Commandes : principes

### 6.1 Correspondance avec Home Assistant

| Type HA | Commande info Jeedom | Commande(s) action Jeedom |
|---|---|---|
| sensor | numérique (avec unité) ou texte | — |
| binary_sensor | binaire `1`/`0` | — |
| switch | binaire `1` = On / `0` = Off | `<id>_on` et `<id>_off` |
| number | numérique | `<id>_set` curseur (min, max et pas du GX) |
| select | texte (libellé de la valeur) | `<id>_set` liste |
| time | texte `HH:MM` | `<id>_set` message (saisir l'heure) |
| button | — | `<id>` |
| device_tracker | texte `latitude,longitude` | — |

L'action est liée à son info (champ « valeur ») : le curseur ou la liste affiche la valeur actuelle.

### 6.2 Format des valeurs

| Nature | Ce que contient l'info |
|---|---|
| Mesure | Nombre arrondi à la précision du GX (ex. `-300.5`) |
| Liste (enum) | **Libellé en anglais** fourni par la librairie (ex. `Bulk`, `No alarm`, `Charger only`) |
| Liste multiple (bitmask) | Libellés séparés par `, ` |
| Binaire | `1` / `0` |
| Heure | `HH:MM` (le GX stocke des minutes ou des secondes, la conversion est automatique) |
| Date | `AAAA-MM-JJ HH:MM:SS` (heure locale) |
| GPS | `latitude,longitude` |

- Les valeurs possibles de chaque commande info sont données dans la [référence](#10-référence-complète-des-commandes) et dans l'[annexe](#11-annexe--listes-de-valeurs-longues).
- **Valeur indisponible** : quand le GX ne publie plus une valeur (appareil déconnecté), l'info garde sa dernière valeur. Aucune valeur vide n'est écrite.

### 6.3 Création des commandes

- **Identifiant logique** : c'est l'identifiant de la métrique Victron. Les parties variables sont remplacées par leur valeur :

| Paramètre dans la référence | Remplacé par | Exemple |
|---|---|---|
| `{phase}`, `{next_phase}` | `l1`, `l2`, `l3` (nom : L1, L2, L3) | `system_consumption_power_l1` |
| `{tracker}` | numéro du tracker MPPT | `solarcharger_tracker_1_power` |
| `{mppt_id}`, `{mpptnumber}` | numéro du MPPT (Multi RS) | `multi_mppt_<n>_power` |
| `{cell_id}` | numéro de cellule | `battery_cell_3_voltage` |
| `{output}` | identifiant de la sortie | `switchable_output_relay_1_state` |
| `{slot}` | numéro de plage de planning (0 à 4) | `system_ess_schedule_charge_0_start` |
| `{gen_id}` | numéro du générateur (0 ou 1) | — |
| `{relay}`, `{device_number}` | numéro du relais ou de l'appareil | — |

- **Noms** : ils sont fournis par la librairie, en anglais. **Le plugin ne réécrit jamais le nom d'une commande existante** : renommez-les librement en français. Si deux commandes ont le même nom, l'identifiant logique est ajouté entre parenthèses.
- **Mises à jour** : à chaque synchronisation, les listes de choix, les min/max, le pas et l'unité des actions sont mis à jour. Le nom, la visibilité et l'historisation sont conservés.
- **Commandes masquées par défaut** : il s'agit des commandes que HA désactive par défaut, marquées « masquée » dans la référence. Elles sont créées mais non visibles : trackers MPPT individuels, MPPT individuels du Multi RS, heartbeat, redémarrage du GX, raison d'arrêt MPPT.
- **Types génériques** : ils sont posés automatiquement sur les infos numériques (indiqués dans la référence). `POWER` pour les puissances, `CONSUMPTION` pour les énergies, `VOLTAGE`, `TEMPERATURE`, `HUMIDITY`, `PRESSURE`, et `BATTERY` pour les SOC.
- **Pourcentages** : min `0` et max `100` sont posés pour un affichage correct des widgets.
- **Commandes « calculées »** : elles sont produites par la librairie à partir d'autres valeurs, par exemple le courant PV total ou la position GPS.
- **Commandes non publiées** : seules les commandes que vos appareils publient réellement sont créées. La référence liste tout ce que le plugin sait gérer.

### 6.4 Commandes action

| Type | Valeur envoyée | Remarques |
|---|---|---|
| `_on` / `_off` | On / Off | — |
| `_set` curseur | valeur du curseur | La librairie refuse une valeur hors plage, avec un message d'erreur dans le log |
| `_set` liste | libellé choisi | Exactement l'un des libellés listés |
| `_set` heure | `06:30`, `6h30`, `0630` ou `630` | — |
| bouton | appui | — |

Le GX renvoie ensuite la nouvelle valeur, et l'info associée se met à jour en une à cinq secondes.

> ⚠️ Certaines actions agissent réellement sur l'installation : le mode du VE.Bus `Off` coupe l'onduleur, `Charger only` coupe la sortie AC sur batterie, et les limites de courant AC sont aussi concernées. Testez d'abord sur un réglage sans conséquence, comme le SOC mini ESS.

---

## 7. Alarmes

Les alarmes (17 sur la batterie/BMS, 10 sur le VE.Bus, plus une sur le capteur d'irradiance, l'entrée numérique et le véhicule électrique) sont des infos **texte** à trois valeurs :

| Valeur | Code GX | Signification |
|---|---|---|
| `No alarm` | 0 | Normal |
| `Warning` | 1 | Pré-alarme |
| `Alarm` | 2 | Alarme |

### Être prévenu, méthode 1 : alertes intégrées de Jeedom

Sur la commande, ouvrez la **Configuration avancée** (roue crantée), onglet **Alertes** :

- Warning : `#value# == "Warning"`
- Danger : `#value# == "Alarm"`

L'équipement passe en orange ou en rouge et l'alerte part vers les canaux d'alerte configurés dans Jeedom.

### Être prévenu, méthode 2 : scénario

- Déclencheur : la commande d'alarme.
- Condition : `#[Objet][Batterie][High cell voltage]# != "No alarm"`.
- Action : notification, avec une priorité plus haute si la valeur vaut `Alarm`.

> Les conditions comparent les libellés anglais. Si une traduction des valeurs est ajoutée un jour, il faudra adapter ces conditions.

---

## 8. Exemples d'utilisation

| Objectif | Commande |
|---|---|
| Garder 30 % de réserve batterie (ESS) | `[Victron][Victron Venus][Régler ESS min SoC limit]` → curseur `30` |
| Forcer l'injection ou le soutirage réseau | `[Victron][Victron Venus][Régler AC power setpoint]` → valeur en W (négatif = injection) |
| Passer le Multi en chargeur seul | `[Victron][MultiPlus-II (ID: 276)][Choisir Mode]` → `Charger only` |
| Remettre le Multi en marche normale | idem → `On` |
| Programmer une charge réseau la nuit | `Régler ESS BatteryLife schedule charge 0 start` → `02:00`, puis `…days` → `Every day`, puis `…enabled On` |
| Piloter un relais du GX | `[Victron][State][State On]` / `State Off` sur l'équipement de la sortie |

Les infos les plus utiles pour un pilotage énergétique, par exemple avec un plugin de gestion d'énergie :

- `system_dc_battery_soc` : SOC de la batterie ;
- `system_dc_battery_power` : puissance batterie ;
- `system_grid_power_l1` (et `l2`/`l3`) : puissance réseau ;
- `system_consumption_power_l1` : consommation ;
- `solarcharger_yield_power` : production des MPPT ;
- `system_ess_min_soc_limit` et `system_ac_power_set_point` : leviers de pilotage.

> Le nom exact entre crochets dépend de votre objet et de vos renommages. Utilisez le sélecteur de commandes de Jeedom.

---

## 9. Logs et dépannage

### Logs

| Log | Contenu |
|---|---|
| `victron` | Partie Jeedom : création des équipements et commandes, actions envoyées, erreurs remontées par le démon |
| `victron_daemon` | Démon Python : connexion au GX, reconnexions, erreurs d'écriture. En *Debug*, chaque topic MQTT est détaillé (très bavard) |
| `victron_update` | Installation des dépendances |

Une fois l'installation validée, repassez le niveau de log en *Info* ou *Défaut*.

### Connexion

- **Avant la première connexion** : le démon réessaie toutes les 10 s, puis espace ses tentatives jusqu'à 5 minutes.
- **Après la première connexion** : la reconnexion est automatique et illimitée. Sans connexion depuis un moment, les valeurs sont marquées indisponibles et ne sont plus mises à jour.
- **Authentification refusée** : nouvel essai toutes les 5 minutes, avec un message dans le log `victron`.

### Problèmes fréquents

| Symptôme | Cause probable / solution |
|---|---|
| Démon « non lançable » | L'adresse du GX n'est pas renseignée |
| « Connexion au GX impossible » | MQTT Access sur OFF, mauvais port (1883/8883), SSL incohérent avec le port, GX injoignable, ou accès limité aux « Paired devices only » |
| « Authentification refusée » | Mot de passe faux, profil changé sur le GX, ou jeton révoqué : refaire l'appairage |
| Aucun équipement créé | « Création automatique des équipements » décochée, ou démon non connecté (voir l'état dans la configuration) |
| Une commande a disparu | Bouton « Recréer les commandes » sur l'équipement |
| Une action ne fait rien | Mode « Lecture seule » actif, valeur hors plage (voir le log), ou réglage non accepté dans l'état actuel du système (ex. ESS désactivé) |
| L'appairage échoue | Le mode appairage n'est pas actif sur le GX, ou le HTTPS du GX n'est pas joignable |
| Dépendances très longues (Debian 11) | Compilation de Python 3.11 par PyEnv : normal la première fois (15 à 30 min) |
| Valeurs en anglais | Comportement normal : les libellés viennent de la librairie (voir section 6.2) |

---

## 10. Référence complète des commandes

Cette référence est générée à partir de la librairie `victron-mqtt` 2026.9.5. Elle liste les **477 métriques** que le plugin sait gérer, regroupées par type d'appareil. Chaque ligne donne la commande info créée et, pour les réglages, les commandes action associées.

**Lecture des colonnes :**

- **Logical ID** : identifiant de la commande info. Les `{paramètres}` sont expliqués en section 6.3.
- **Info Jeedom** : sous-type de la commande info. Les mentions entre parenthèses indiquent :
  - le type générique posé (`POWER`, `BATTERY`…) ;
  - `masquée` : commande créée non visible ;
  - `calculée` : valeur produite par la librairie.
- **Valeurs possibles / plage** : contenu exact de l'info pour les listes, ou plage autorisée pour les réglages.
- **Action(s) créée(s)** : identifiant logique et nom des commandes action générées en plus de l'info.

### Système (Victron Venus / GX) — inclut les réglages ESS, DVCC, Dynamic ESS, relais, réseau

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `solarcharger_current` | PV bus current | numérique | A | — |  |
| `switchable_output_{output}_state` | State | binaire |  | `1` = On, `0` = Off | `switchable_output_{output}_state_on` « State On », `switchable_output_{output}_state_off` « State Off » |
| `system_ac_active_input_source` | AC active input source | texte |  | `Unknown` · `Grid` · `Generator` · `Shore power` · `Not connected` |  |
| `system_ac_export_limit` | AC export limit | numérique | A | Plage : -1 → 100, pas 1 | `system_ac_export_limit_set` curseur « Régler AC export limit » |
| `system_ac_input_1_type` | AC input 1 source type | texte |  | `Not used` · `Grid` · `Generator` · `Shore` | `system_ac_input_1_type_set` liste « Choisir AC input 1 source type » |
| `system_ac_input_2_type` | AC input 2 source type | texte |  | `Not used` · `Grid` · `Generator` · `Shore` | `system_ac_input_2_type_set` liste « Choisir AC input 2 source type » |
| `system_ac_input_limit` | AC input limit | numérique | A | Plage : -1 → 100, pas 1 | `system_ac_input_limit_set` curseur « Régler AC input limit » |
| `system_ac_loads_{phase}` | AC loads on {phase} | numérique (POWER) | W | — |  |
| `system_ac_power_set_point` | AC power setpoint | numérique (POWER) | W | Plage : -10000 → 10000, pas 10, ajustée selon l'appareil | `system_ac_power_set_point_set` curseur « Régler AC power setpoint » |
| `system_consumption_current_{phase}` | Consumption current {phase} | numérique | A | — |  |
| `system_consumption_on_output_phases` | Consumption on output phases | numérique | phases | — |  |
| `system_consumption_phases` | Consumption phases | numérique | phases | — |  |
| `system_consumption_power_{phase}` | Consumption power {phase} | numérique (POWER) | W | — |  |
| `system_control_active_soc_limit` | Active SoC limit | numérique (BATTERY) | % | — |  |
| `system_control_scheduled_soc` | Scheduled SoC | numérique (BATTERY) | % | — |  |
| `system_critical_loads_{phase}` | Critical loads on {phase} | numérique (POWER) | W | — |  |
| `system_dc_alternator_power` | DC alternator power | numérique (POWER) | W | — |  |
| `system_dc_battery_charge_energy` | DC battery charge energy | numérique (CONSUMPTION, calculée) | kWh | — |  |
| `system_dc_battery_current` | DC battery current | numérique | A | — |  |
| `system_dc_battery_discharge_energy` | DC battery discharge energy | numérique (CONSUMPTION, calculée) | kWh | — |  |
| `system_dc_battery_power` | DC battery power | numérique (POWER) | W | — |  |
| `system_dc_battery_soc` | DC battery charge | numérique (BATTERY) | % | — |  |
| `system_dc_battery_state` | DC battery state | texte |  | `Idle` · `Charging` · `Discharging` |  |
| `system_dc_battery_voltage` | DC battery voltage | numérique (VOLTAGE) | V | — |  |
| `system_dc_consumption` | DC consumption | numérique (POWER) | W | — |  |
| `system_dc_pv_current` | PV current | numérique | A | — |  |
| `system_dc_pv_energy` | PV energy | numérique (CONSUMPTION, calculée) | kWh | — |  |
| `system_dc_pv_power` | PV power | numérique (POWER) | W | — |  |
| `system_dvcc` | DVCC | binaire (calculée) |  | `1` = On, `0` = Off | `system_dvcc_on` « DVCC On », `system_dvcc_off` « DVCC Off » |
| `system_dvcc_state` | DVCC state | texte (calculée) |  | `Off` · `On` · `Forced off` · `Forced on` |  |
| `system_dynamicess_active` | Dynamic ESS active | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `system_dynamicess_allow_gridfeedin` | Dynamic ESS allow grid feed-in | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `system_dynamicess_available` | Dynamic ESS available | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `system_dynamicess_available_overhead` | Dynamic ESS available overhead | numérique (POWER) | W | — |  |
| `system_dynamicess_error` | Dynamic ESS error | texte |  | `No error` · `No ESS` · `ESS mode` · `No matching schedule` · `SoC low` · `Battery capacity not configured` |  |
| `system_dynamicess_last_scheduled_end` | Dynamic ESS last scheduled end | texte |  | Date `AAAA-MM-JJ HH:MM:SS` |  |
| `system_dynamicess_last_scheduled_start` | Dynamic ESS last scheduled start | texte |  | Date `AAAA-MM-JJ HH:MM:SS` |  |
| `system_dynamicess_minimum_soc` | Dynamic ESS minimum SoC | numérique (BATTERY) | % | — |  |
| `system_dynamicess_reactive_strategy` | Dynamic ESS reactive strategy | texte |  | 27 valeurs → [liste DESSReactiveStrategy](#liste-dessreactivestrategy) |  |
| `system_dynamicess_restrictions` | Dynamic ESS restrictions | texte |  | `No restrictions between battery and the grid` · `Battery to grid energy flow restricted` · `Grid to battery energy flow restricted` · `No energy flow between battery and grid` |  |
| `system_dynamicess_schedule_count` | Dynamic ESS number of schedules | numérique | schedules | — |  |
| `system_dynamicess_strategy` | Dynamic ESS strategy | texte |  | `Target SoC` · `Self-consume` · `Pro battery` · `Pro grid` |  |
| `system_dynamicess_target_soc` | Dynamic ESS target SoC | numérique (BATTERY) | % | — |  |
| `system_ess_always_peak_shave` | ESS always peak shave | binaire |  | `1` = On, `0` = Off | `system_ess_always_peak_shave_on` « ESS always peak shave On », `system_ess_always_peak_shave_off` « ESS always peak shave Off » |
| `system_ess_battery_use` | ESS only critical loads from battery | binaire |  | `1` = On, `0` = Off | `system_ess_battery_use_on` « ESS only critical loads from battery On », `system_ess_battery_use_off` « ESS only critical loads from battery Off » |
| `system_ess_batterylife_state` | ESS mode | texte (calculée) |  | `Optimized (with BatteryLife)` · `Optimized (without BatteryLife)` · `Keep batteries charged` · `External control` | `system_ess_batterylife_state_set` liste « Choisir ESS mode » |
| `system_ess_batterylife_state_sensor` | ESS BatteryLife state | texte (calculée) |  | 12 valeurs → [liste ESSState](#liste-essstate) |  |
| `system_ess_max_charge_current` | ESS max charge current | numérique | A | Plage : pas 1, ajustée selon l'appareil | `system_ess_max_charge_current_set` curseur « Régler ESS max charge current » |
| `system_ess_max_charge_power` | ESS max charge power limit | numérique (POWER) | W | Plage : -1 → 1e+06, pas 50, ajustée selon l'appareil | `system_ess_max_charge_power_set` curseur « Régler ESS max charge power limit » |
| `system_ess_max_charge_voltage` | ESS max charge voltage | numérique (VOLTAGE) | V | Plage : pas 0.1, ajustée selon l'appareil | `system_ess_max_charge_voltage_set` curseur « Régler ESS max charge voltage » |
| `system_ess_max_feed_in_power` | ESS max feed-in power | numérique (POWER) | W | Plage : -1 → 1e+06, pas 1, ajustée selon l'appareil | `system_ess_max_feed_in_power_set` curseur « Régler ESS max feed-in power » |
| `system_ess_max_inverter_power_limit` | ESS max inverter power limit | numérique (POWER) | W | Plage : pas 50, ajustée selon l'appareil | `system_ess_max_inverter_power_limit_set` curseur « Régler ESS max inverter power limit » |
| `system_ess_min_soc_limit` | ESS min SoC limit | numérique (BATTERY) | % | Plage : 0 → 100, pas 5, ajustée selon l'appareil | `system_ess_min_soc_limit_set` curseur « Régler ESS min SoC limit » |
| `system_ess_mode` | ESS mode (Hub4) | texte |  | `Optimized mode or 'keep batteries charged' and phase compensation enabled` · `Optimized mode or 'keep batteries charged' and phase compensation disabled` · `External control` | `system_ess_mode_set` liste « Choisir ESS mode (Hub4) » |
| `system_ess_schedule_charge_{slot}_days` | ESS BatteryLife schedule charge {slot} days | texte |  | 20 valeurs → [liste ChargeSchedule](#liste-chargeschedule) | `system_ess_schedule_charge_{slot}_days_set` liste « Choisir ESS BatteryLife schedule charge {slot} days » |
| `system_ess_schedule_charge_{slot}_duration` | ESS BatteryLife schedule charge {slot} duration | numérique | min | Plage : 0 → 1440 | `system_ess_schedule_charge_{slot}_duration_set` curseur « Régler ESS BatteryLife schedule charge {slot} duration » |
| `system_ess_schedule_charge_{slot}_enabled` | ESS BatteryLife schedule charge {slot} enabled | binaire (calculée) |  | `1` = On, `0` = Off | `system_ess_schedule_charge_{slot}_enabled_on` « ESS BatteryLife schedule charge {slot} enabled On », `system_ess_schedule_charge_{slot}_enabled_off` « ESS BatteryLife schedule charge {slot} enabled Off » |
| `system_ess_schedule_charge_{slot}_soc` | ESS BatteryLife schedule charge {slot} SoC | numérique (BATTERY) | % | Plage : 0 → 100, pas 5 | `system_ess_schedule_charge_{slot}_soc_set` curseur « Régler ESS BatteryLife schedule charge {slot} SoC » |
| `system_ess_schedule_charge_{slot}_start` | ESS BatteryLife schedule charge {slot} start | texte | min | Heure `HH:MM` | `system_ess_schedule_charge_{slot}_start_set` message « Régler ESS BatteryLife schedule charge {slot} start » (saisir `06:30`) |
| `system_generator_load_{phase}` | Genset load {phase} | numérique (POWER) | W | — |  |
| `system_grid_current_{phase}` | Grid current {phase} | numérique | A | — |  |
| `system_grid_phases` | Grid phases | numérique | phases | — |  |
| `system_grid_power_{phase}` | Grid power {phase} | numérique (POWER) | W | — |  |
| `system_pv_on_grid_current_{phase}` | PV on grid current {phase} | numérique | A | — |  |
| `system_pv_on_grid_phases` | PV on grid phases | numérique | phases | — |  |
| `system_pv_on_grid_power_{phase}` | PV on grid power {phase} | numérique (POWER) | W | — |  |
| `system_pv_on_output_current_{phase}` | PV on output current {phase} | numérique | A | — |  |
| `system_pv_on_output_phases` | PV on output phases | numérique | phases | — |  |
| `system_pv_on_output_power_{phase}` | PV on output power {phase} | numérique (POWER) | W | — |  |
| `system_relay_{relay}` | Relay {relay} state | binaire |  | `1` = On, `0` = Off | `system_relay_{relay}_on` « Relay {relay} state On », `system_relay_{relay}_off` « Relay {relay} state Off » |
| `system_relay_{relay}_custom_name` | Relay {relay} custom name | texte |  | — |  |
| `system_settings_dess_mode` | DESS mode | texte |  | `Off` · `Auto / VRM` · `Buy` · `Sell` · `Node-RED` | `system_settings_dess_mode_set` liste « Choisir DESS mode » |
| `system_settings_overvoltage_feedin` | DC-coupled PV - feed in excess | binaire |  | `1` = On, `0` = Off | `system_settings_overvoltage_feedin_on` « DC-coupled PV - feed in excess On », `system_settings_overvoltage_feedin_off` « DC-coupled PV - feed in excess Off » |
| `system_settings_prevent_ac_feedin` | AC-coupled PV - feed in excess | binaire |  | `1` = On, `0` = Off | `system_settings_prevent_ac_feedin_on` « AC-coupled PV - feed in excess On », `system_settings_prevent_ac_feedin_off` « AC-coupled PV - feed in excess Off » |
| `system_state` | System state | texte |  | 22 valeurs → [liste State](#liste-state) |  |
| `system_vrm_portal_mode` | VRM portal access level | texte |  | `Off` · `Read-only` · `Full` | `system_vrm_portal_mode_set` liste « Choisir VRM portal access level » |

### Plateforme GX (redémarrage, firmware) — rattaché au Système

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `platform_device_reboot` | Device reboot | — (action seule) |  | `Off` · `On` | `platform_device_reboot` « Device reboot » |
| `platform_venus_firmware_available_version` | Available version | texte |  | — |  |
| `platform_venus_firmware_installed_version` | Installed version | texte |  | — |  |
| `platform_venus_firmware_progress` | Firmware update progress | numérique | % | — |  |
| `platform_venus_firmware_state` | Firmware update state | texte |  | `Update file not found` · `Error during update` · `Error during check` · `Idle` · `Checking` · `Downloading and installing` · `Rebooting` |  |

### Heartbeat — rattaché au Système

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `system_heartbeat` | GX system heartbeat | numérique (masquée) | s | — |  |

### Hub4 (ESS)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `hub4_ac_grid_setpoint` | AC grid setpoint | numérique (POWER) | W | Plage : -32767 → 32767 | `hub4_ac_grid_setpoint_set` curseur « Régler AC grid setpoint » |
| `hub4_force_charge` | Force charge | binaire |  | `1` = On, `0` = Off | `hub4_force_charge_on` « Force charge On », `hub4_force_charge_off` « Force charge Off » |
| `hub4_max_charge_power` | Maximum charge power | numérique (POWER) | W | Plage : -1 → 1e+06 | `hub4_max_charge_power_set` curseur « Régler Maximum charge power » |
| `hub4_max_discharge_power` | Maximum discharge power | numérique (POWER) | W | Plage : -1 → 1e+06 | `hub4_max_discharge_power_set` curseur « Régler Maximum discharge power » |

### VE.Bus (MultiPlus, Quattro, MultiPlus-II…)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `multiplus_assist_current_boost_factor` | Assist current boost factor | numérique |  | Plage : 0.25 → 3.5, pas 0.125 | `multiplus_assist_current_boost_factor_set` curseur « Régler Assist current boost factor » |
| `vebus_ac_power_setpoint_{phase}` | AC power setpoint {phase} | numérique (POWER) | W | Plage : -10000 → 10000 | `vebus_ac_power_setpoint_{phase}_set` curseur « Régler AC power setpoint {phase} » |
| `vebus_device_{device_number}_input_power_l1` | {device_number} line 1 input power | numérique (POWER) | W | — |  |
| `vebus_device_{device_number}_input_power_{phase}` | {device_number} line {phase} input power | numérique (POWER) | W | — |  |
| `vebus_device_{device_number}_inverted_power` | {device_number} inverted power | numérique (POWER) | W | — |  |
| `vebus_device_{device_number}_output_power_l1` | {device_number} line 1 output power | numérique (POWER) | W | — |  |
| `vebus_device_{device_number}_output_power_{phase}` | {device_number} line {phase} output power | numérique (POWER) | W | — |  |
| `vebus_device_{device_number}_power_assist_enabled` | {device_number} PowerAssist enabled | binaire |  | `1` = On, `0` = Off | `vebus_device_{device_number}_power_assist_enabled_on` « {device_number} PowerAssist enabled On », `vebus_device_{device_number}_power_assist_enabled_off` « {device_number} PowerAssist enabled Off » |
| `vebus_energy_ac_in1_to_ac_out` | Energy from AC-in-1 to AC-out | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_ac_in1_to_inverter` | Energy from AC-in-1 to inverter | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_ac_in2_to_ac_out` | Energy from AC-in-2 to AC-out | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_ac_in2_to_inverter` | Energy from AC-in-2 to inverter | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_ac_out_to_ac_in1` | Energy from AC-out to AC-in-1 | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_ac_out_to_ac_in2` | Energy from AC-out to AC-in-2 | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_inverter_to_ac_in1` | Energy from inverter to AC-in-1 | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_inverter_to_ac_in2` | Energy from inverter to AC-in-2 | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_inverter_to_ac_out` | Energy from inverter to AC-out | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_energy_out_to_inverter` | Energy from out to inverter | numérique (CONSUMPTION) | kWh | — |  |
| `vebus_hub4_disable_charge` | Hub4 disable charge | binaire |  | `1` = On, `0` = Off | `vebus_hub4_disable_charge_on` « Hub4 disable charge On », `vebus_hub4_disable_charge_off` « Hub4 disable charge Off » |
| `vebus_hub4_disable_feed_in` | Hub4 disable feed-in | binaire |  | `1` = On, `0` = Off | `vebus_hub4_disable_feed_in_on` « Hub4 disable feed-in On », `vebus_hub4_disable_feed_in_off` « Hub4 disable feed-in Off » |
| `vebus_hub4_do_not_feed_in_overvoltage` | Hub4 do not feed in on overvoltage | binaire |  | `1` = On, `0` = Off | `vebus_hub4_do_not_feed_in_overvoltage_on` « Hub4 do not feed in on overvoltage On », `vebus_hub4_do_not_feed_in_overvoltage_off` « Hub4 do not feed in on overvoltage Off » |
| `vebus_hub4_fix_solar_offset_100mv` | Hub4 fix solar offset to 100mV | binaire |  | `1` = On, `0` = Off | `vebus_hub4_fix_solar_offset_100mv_on` « Hub4 fix solar offset to 100mV On », `vebus_hub4_fix_solar_offset_100mv_off` « Hub4 fix solar offset to 100mV Off » |
| `vebus_hub4_target_power_is_max_feed_in` | Hub4 target power is max feed-in | binaire |  | `1` = On, `0` = Off | `vebus_hub4_target_power_is_max_feed_in_on` « Hub4 target power is max feed-in On », `vebus_hub4_target_power_is_max_feed_in_off` « Hub4 target power is max feed-in Off » |
| `vebus_inverter_active_input` | Active AC input | texte |  | `Unknown` · `Grid` · `Generator` · `Shore power` · `Not connected` |  |
| `vebus_inverter_alarm_grid_lost` | Grid lost alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_high_dc_current` | High DC current alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_high_dc_voltage` | High DC voltage alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_high_temperature` | High temperature alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_low_battery` | Low battery alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_overload` | Overload alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_phase_rotation` | Phase rotation alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_ripple` | Ripple alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_temperature_sensor` | Temperature sensor alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_alarm_voltage_sensor` | Voltage sensor alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `vebus_inverter_connected` | Connected | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `vebus_inverter_current_limit` | Current limit | numérique | A | Plage : 0 → 16, ajustée selon l'appareil | `vebus_inverter_current_limit_set` curseur « Régler Current limit » |
| `vebus_inverter_dc_current` | DC current | numérique | A | — |  |
| `vebus_inverter_dc_power` | DC power | numérique (POWER) | W | — |  |
| `vebus_inverter_dc_temperature` | DC temperature | numérique (TEMPERATURE) | °C | — |  |
| `vebus_inverter_dc_voltage` | DC voltage | numérique (VOLTAGE) | V | — |  |
| `vebus_inverter_ignoreacin1_onoff_control` | Control ignore AC-in-1 | binaire |  | `1` = On, `0` = Off | `vebus_inverter_ignoreacin1_onoff_control_on` « Control ignore AC-in-1 On », `vebus_inverter_ignoreacin1_onoff_control_off` « Control ignore AC-in-1 Off » |
| `vebus_inverter_ignoreacin1_state` | State of ignore AC-in-1 | texte |  | `Off` · `On` |  |
| `vebus_inverter_input_apparent_power_{phase}` | Input apparent power {phase} | numérique | VA | — |  |
| `vebus_inverter_input_current_{phase}` | Input current {phase} | numérique | A | — |  |
| `vebus_inverter_input_frequency_{phase}` | Input frequency {phase} | numérique | Hz | — |  |
| `vebus_inverter_input_power_{phase}` | Input power {phase} | numérique (POWER) | W | — |  |
| `vebus_inverter_input_voltage_{phase}` | Input voltage {phase} | numérique (VOLTAGE) | V | — |  |
| `vebus_inverter_mode` | Mode | texte |  | `Charger only` · `Inverter only` · `On` · `Off` | `vebus_inverter_mode_set` liste « Choisir Mode » |
| `vebus_inverter_output_apparent_power_{phase}` | Output apparent power {phase} | numérique | VA | — |  |
| `vebus_inverter_output_current_{phase}` | Output current {phase} | numérique | A | — |  |
| `vebus_inverter_output_frequency_{phase}` | Output frequency {phase} | numérique | Hz | — |  |
| `vebus_inverter_output_power_{phase}` | Output power {phase} | numérique (POWER) | W | — |  |
| `vebus_inverter_output_voltage_{phase}` | Output voltage {phase} | numérique (VOLTAGE) | V | — |  |
| `vebus_inverter_prefer_renewable_energy` | Prefer renewable energy | binaire (calculée) |  | `1` = On, `0` = Off | `vebus_inverter_prefer_renewable_energy_on` « Prefer renewable energy On », `vebus_inverter_prefer_renewable_energy_off` « Prefer renewable energy Off » |
| `vebus_inverter_remote_generator_selected` | Remote generator selected | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `vebus_inverter_setting_alarm_grid_lost` | Grid lost alarm setting | binaire |  | `1` = On, `0` = Off | `vebus_inverter_setting_alarm_grid_lost_on` « Grid lost alarm setting On », `vebus_inverter_setting_alarm_grid_lost_off` « Grid lost alarm setting Off » |
| `vebus_inverter_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |
| `vebus_pvinverter_disable` | Vebus PV inverter disable | binaire |  | `1` = On, `0` = Off | `vebus_pvinverter_disable_on` « Vebus PV inverter disable On », `vebus_pvinverter_disable_off` « Vebus PV inverter disable Off » |

### Multi RS Solar

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `multi_acin1_to_acout` | AC-in-1 to AC-out | numérique (CONSUMPTION) | kWh | — |  |
| `multi_acin1_to_inverter` | AC-in-1 to inverter | numérique (CONSUMPTION) | kWh | — |  |
| `multi_acin_current_{phase}` | Input current on {phase} | numérique | A | — |  |
| `multi_acin_power_{phase}` | Input power on {phase} | numérique (POWER) | W | — |  |
| `multi_acin_voltage_{phase}` | Input voltage on {phase} | numérique (VOLTAGE) | V | — |  |
| `multi_acout_current_{phase}` | Output current on {phase} | numérique | A | — |  |
| `multi_acout_power_{phase}` | Output power on {phase} | numérique (POWER) | W | — |  |
| `multi_acout_to_acin1` | AC-out to AC-in-1 | numérique (CONSUMPTION) | kWh | — |  |
| `multi_acout_to_inverter` | AC-out to inverter | numérique (CONSUMPTION) | kWh | — |  |
| `multi_acout_voltage_{phase}` | Output voltage on {phase} | numérique (VOLTAGE) | V | — |  |
| `multi_acout_{output}_current_{phase}` | AC-out-{output} current on {phase} | numérique | A | — |  |
| `multi_acout_{output}_power_{phase}` | AC-out-{output} power on {phase} | numérique (POWER) | W | — |  |
| `multi_acout_{output}_voltage_{phase}` | AC-out-{output} voltage on {phase} | numérique (VOLTAGE) | V | — |  |
| `multi_active_input` | Active AC input | texte |  | `AC input 1` · `AC input 2` · `Disconnected` |  |
| `multi_dc_temperature` | DC temperature | numérique (TEMPERATURE) | °C | — |  |
| `multi_disable_charge` | ESS disable charge | binaire |  | `1` = On, `0` = Off | `multi_disable_charge_on` « ESS disable charge On », `multi_disable_charge_off` « ESS disable charge Off » |
| `multi_disable_feed_in` | ESS disable feed-in | binaire |  | `1` = On, `0` = Off | `multi_disable_feed_in_on` « ESS disable feed-in On », `multi_disable_feed_in_off` « ESS disable feed-in Off » |
| `multi_ess_ac_power_setpoint` | ESS AC power setpoint | numérique (POWER) | W | — |  |
| `multi_ess_min_soc_limit` | ESS minimum SoC limit | numérique (BATTERY) | % | Plage : 0 → 100, pas 5 | `multi_ess_min_soc_limit_set` curseur « Régler ESS minimum SoC limit » |
| `multi_ess_mode` | ESS mode | texte |  | `Self-consumption (BatteryLife)` · `Self-consumption` · `Keep charged` · `External control` |  |
| `multi_inverter_power_setpoint` | Inverter power setpoint | numérique (POWER) | W | — |  |
| `multi_inverter_to_acin1` | Inverter to AC-in-1 | numérique (CONSUMPTION) | kWh | — |  |
| `multi_inverter_to_acout` | Inverter to AC-out | numérique (CONSUMPTION) | kWh | — |  |
| `multi_max_power_today` | Max power today | numérique (POWER) | W | — |  |
| `multi_max_power_yesterday` | Max power yesterday | numérique (POWER) | W | — |  |
| `multi_mppt_{mppt_id}_yield_today` | MPPT {mppt_id} yield today | numérique (CONSUMPTION, masquée) | kWh | — |  |
| `multi_mppt_{mppt_id}_yield_yesterday` | MPPT {mppt_id} yield yesterday | numérique (CONSUMPTION, masquée) | kWh | — |  |
| `multi_mppt_{mpptnumber}_current` | MPPT {mpptnumber} current | numérique (masquée, calculée) | A | — |  |
| `multi_mppt_{mpptnumber}_power` | MPPT {mpptnumber} power | numérique (POWER, masquée) | W | — |  |
| `multi_mppt_{mpptnumber}_state` | MPPT {mpptnumber} state | texte (masquée) |  | `Off` · `Voltage/current limited` · `MPPT active` · `Not available` |  |
| `multi_mppt_{mpptnumber}_voltage` | MPPT {mpptnumber} PV voltage | numérique (VOLTAGE, masquée) | V | — |  |
| `multi_phases` | Phases | numérique | phases | — |  |
| `multi_pv_power_total` | PV power total | numérique (POWER) | W | — |  |
| `multi_relay0_state` | Relay on Multi RS state | binaire |  | `1` = On, `0` = Off | `multi_relay0_state_on` « Relay on Multi RS state On », `multi_relay0_state_off` « Relay on Multi RS state Off » |
| `multi_shore_current_limit` | Shore current limit | numérique | A | Plage : 0 → 200, pas 0.1 | `multi_shore_current_limit_set` curseur « Régler Shore current limit » |
| `multi_solar_to_acin1` | Solar to AC-in-1 | numérique (CONSUMPTION) | kWh | — |  |
| `multi_solar_to_acout` | Solar to AC-out | numérique (CONSUMPTION) | kWh | — |  |
| `multi_solar_to_battery` | Solar to battery | numérique (CONSUMPTION) | kWh | — |  |
| `multi_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |
| `multi_total_pv_yield` | Total PV yield user | numérique (CONSUMPTION) | kWh | — |  |
| `multi_yield_today` | Yield today | numérique (CONSUMPTION) | kWh | — |  |
| `multi_yield_yesterday` | Yield yesterday | numérique (CONSUMPTION) | kWh | — |  |

### Système AC (Multi RS en parallèle) — rattaché au Système

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `acsystem_mode` | Mode | texte |  | `Charger only` · `Inverter only` · `On` · `Off` · `Passthrough` | `acsystem_mode_set` liste « Choisir Mode » |

### Onduleur (Phoenix, Inverter RS)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `inverter_alarm_high_temperature` | High temperature alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_high_voltage` | High voltage alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_high_voltage_ac_out` | High voltage AC-out alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_low_temperature` | Low temperature alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_low_voltage` | Low voltage alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_low_voltage_ac_out` | Low voltage AC-out alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_overload` | Overload alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_alarm_ripple` | Ripple alarm | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `inverter_mode` | Mode | texte |  | `Inverter` · `Off` · `Eco` | `inverter_mode_set` liste « Choisir Mode » |
| `inverter_output_apparent_power_{phase}` | Output apparent power {phase} | numérique | VA | — |  |
| `inverter_output_current_{phase}` | Output current {phase} | numérique | A | — |  |
| `inverter_output_power_{phase}` | Output power {phase} | numérique (POWER) | W | — |  |
| `inverter_output_voltage_{phase}` | Output voltage {phase} | numérique (VOLTAGE) | V | — |  |
| `inverter_pv_power_total` | PV power total | numérique (POWER) | W | — |  |
| `inverter_pv_voltage` | PV bus voltage | numérique (VOLTAGE) | V | — |  |
| `inverter_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |
| `inverter_total_pv_yield_system` | Total PV yield system | numérique (CONSUMPTION) | kWh | — |  |
| `inverter_total_pv_yield_user` | Total PV yield user | numérique (CONSUMPTION) | kWh | — |  |

### Chargeur AC (Phoenix Smart / Skylla)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `charger_ac_in_current_limit` | AC input current limit | numérique | A | Plage : 0 → 32, pas 0.1, ajustée selon l'appareil | `charger_ac_in_current_limit_set` curseur « Régler AC input current limit » |
| `charger_ac_in_current_{phase}` | AC input current {phase} | numérique | A | — |  |
| `charger_dc_current_{output}` | DC output {output} current | numérique | A | — |  |
| `charger_dc_voltage_{output}` | DC output {output} voltage | numérique (VOLTAGE) | V | — |  |
| `charger_error_code` | Error code | texte |  | 20 valeurs → [liste ErrorCode](#liste-errorcode) |  |
| `charger_mode` | Mode | binaire |  | `1` = On, `0` = Off | `charger_mode_on` « Mode On », `charger_mode_off` « Mode Off » |
| `charger_nr_of_outputs` | Number of outputs | numérique | outputs | — |  |
| `charger_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |

### Chargeur solaire (MPPT)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `solarcharger_charge_current_limit` | Charge current limit | numérique | A | Plage : 0 → selon l'appareil, ajustée selon l'appareil | `solarcharger_charge_current_limit_set` curseur « Régler Charge current limit » |
| `solarcharger_dc_current` | DC (battery) bus current | numérique | A | — |  |
| `solarcharger_dc_voltage` | DC (battery) bus voltage | numérique (VOLTAGE) | V | — |  |
| `solarcharger_device_off_reason` | Device-off reason | texte (masquée) |  | Plusieurs valeurs possibles, séparées par `, ` : 14 valeurs → [liste SolarChargerDeviceOffReason](#liste-solarchargerdeviceoffreason) |  |
| `solarcharger_error_code` | Error code | texte |  | 20 valeurs → [liste ErrorCode](#liste-errorcode) |  |
| `solarcharger_load_current` | Load bus current | numérique | A | — |  |
| `solarcharger_load_state` | Load state | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `solarcharger_max_battery_voltage_today` | Max battery voltage today | numérique (VOLTAGE) | V | — |  |
| `solarcharger_max_power_today` | Max power today | numérique (POWER) | W | — |  |
| `solarcharger_max_power_yesterday` | Max power yesterday | numérique (POWER) | W | — |  |
| `solarcharger_min_battery_voltage_today` | Min battery voltage today | numérique (VOLTAGE) | V | — |  |
| `solarcharger_mode` | Mode | binaire |  | `1` = On, `0` = Off | `solarcharger_mode_on` « Mode On », `solarcharger_mode_off` « Mode Off » |
| `solarcharger_mppt_operation_mode` | MPPT operation mode | texte |  | `Off` · `Voltage/current limited` · `MPPT active` · `Not available` |  |
| `solarcharger_pv_current` | PV current | numérique (calculée) | A | — |  |
| `solarcharger_relay_state` | Relay state | binaire |  | `1` = On, `0` = Off | `solarcharger_relay_state_on` « Relay state On », `solarcharger_relay_state_off` « Relay state Off » |
| `solarcharger_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |
| `solarcharger_temperature` | Temperature | numérique (TEMPERATURE) | °C | — |  |
| `solarcharger_time_in_absorption_today` | Time in absorption today | numérique | min | — |  |
| `solarcharger_time_in_bulk_today` | Time in bulk today | numérique | min | — |  |
| `solarcharger_time_in_float_today` | Time in float today | numérique | min | — |  |
| `solarcharger_total_pv_yield_system` | Total PV yield system | numérique (CONSUMPTION) | kWh | — |  |
| `solarcharger_tracker_{tracker}_current` | PV tracker {tracker} current | numérique (masquée, calculée) | A | — |  |
| `solarcharger_tracker_{tracker}_max_power_today` | Tracker {tracker} max power today | numérique (POWER, masquée) | W | — |  |
| `solarcharger_tracker_{tracker}_max_voltage_today` | Tracker {tracker} max voltage today | numérique (VOLTAGE, masquée) | V | — |  |
| `solarcharger_tracker_{tracker}_name` | PV tracker {tracker} name | texte (masquée) |  | — |  |
| `solarcharger_tracker_{tracker}_operation_mode` | PV tracker {tracker} operation mode | texte (masquée) |  | `Off` · `Voltage/current limited` · `MPPT active` · `Not available` |  |
| `solarcharger_tracker_{tracker}_power` | PV tracker {tracker} power | numérique (POWER, masquée) | W | — |  |
| `solarcharger_tracker_{tracker}_voltage` | PV tracker {tracker} voltage | numérique (VOLTAGE, masquée) | V | — |  |
| `solarcharger_tracker_{tracker}_yield_today` | Tracker {tracker} yield today | numérique (CONSUMPTION, masquée) | kWh | — |  |
| `solarcharger_voltage` | PV bus voltage | numérique (VOLTAGE) | V | — |  |
| `solarcharger_yield_power` | PV yield power | numérique (POWER) | W | — |  |
| `solarcharger_yield_today` | Yield today | numérique (CONSUMPTION) | kWh | — |  |
| `solarcharger_yield_total` | Total yield | numérique (CONSUMPTION) | kWh | — |  |
| `solarcharger_yield_yesterday` | Yield yesterday | numérique (CONSUMPTION) | kWh | — |  |

### Onduleur PV (Fronius, SMA… sur AC)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `pvinverter_current_{phase}` | Current {phase} | numérique | A | — |  |
| `pvinverter_power_limit` | Power limit | numérique (POWER) | W | Plage : 0 → 10000 | `pvinverter_power_limit_set` curseur « Régler Power limit » |
| `pvinverter_power_total` | Power total | numérique (POWER) | W | — |  |
| `pvinverter_power_{phase}` | Power {phase} | numérique (POWER) | W | — |  |
| `pvinverter_voltage_{phase}` | Voltage {phase} | numérique (VOLTAGE) | V | — |  |
| `pvinverter_yield_total` | Total yield | numérique (CONSUMPTION) | kWh | — |  |
| `pvinverter_yield_{phase}` | Yield {phase} | numérique (CONSUMPTION) | kWh | — |  |

### Batterie (SmartShunt, BMV, BMS Lynx, batteries CAN…)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `auxiliary_battery_voltage` | Auxiliary battery voltage | numérique (VOLTAGE) | V | — |  |
| `battery_allow_to_charge` | Allow to charge | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `battery_allow_to_discharge` | Allow to discharge | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `battery_automatic_syncs` | Automatic syncs | numérique | syncs | — |  |
| `battery_average_discharge` | Average discharge | numérique | Ah | — |  |
| `battery_bms_cable` | BMS cable | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_bms_mode` | BMS mode | texte |  | `On` · `Off` · `Standby` | `battery_bms_mode_set` liste « Choisir BMS mode » |
| `battery_capacity` | Capacity | numérique | Ah | — |  |
| `battery_cell_imbalance` | Cell imbalance | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_cell_voltage_deviation` | Cell voltage deviation | numérique (VOLTAGE) | V | — |  |
| `battery_cell_{cell_id}_voltage` | Cell {cell_id} voltage | numérique (VOLTAGE) | V | — |  |
| `battery_charged_energy` | Charged energy | numérique (CONSUMPTION) | kWh | — |  |
| `battery_consumed_amphours` | Consumed amp-hours | numérique | Ah | — |  |
| `battery_cumulative_ah_drawn` | Cumulative Ah drawn | numérique | Ah | — |  |
| `battery_current` | DC bus current | numérique | A | — |  |
| `battery_deepest_discharge` | Deepest discharge | numérique | Ah | — |  |
| `battery_discharged_energy` | Discharged energy | numérique (CONSUMPTION) | kWh | — |  |
| `battery_fuse_blown` | Fuse blown | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_cell_voltage` | High cell voltage | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_charge_current` | High charge current | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_charge_temperature` | High charge temperature | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_discharge_current` | High discharge current | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_internal_temperature` | High internal temperature | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_temperature` | High temperature | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_high_voltage` | High voltage | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_installed_capacity` | Installed capacity | numérique | Ah | — |  |
| `battery_internal_failure` | Internal failure | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_last_discharge` | Last discharge | numérique | Ah | — |  |
| `battery_low_cell_voltage` | Low cell voltage | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_low_charge_temperature` | Low charge temperature | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_low_soc` | Low state of charge | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_low_temperature` | Low temperature | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_low_voltage` | Low voltage | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_max_cell_temperature` | Maximum cell temperature | numérique (TEMPERATURE) | °C | — |  |
| `battery_max_cell_voltage` | Maximum cell voltage | numérique (VOLTAGE) | V | — |  |
| `battery_max_charge_current` | Maximum allowed charge current | numérique | A | — |  |
| `battery_max_charge_voltage` | Maximum allowed charging voltage | numérique (VOLTAGE) | V | — |  |
| `battery_max_discharge_current` | Maximum allowed discharge current | numérique | A | — |  |
| `battery_max_temperature_cell_id` | Maximum temperature cell ID | texte |  | — |  |
| `battery_max_voltage_cell_id` | Maximum voltage cell ID | texte |  | — |  |
| `battery_maximum_voltage` | Maximum voltage | numérique (VOLTAGE) | V | — |  |
| `battery_mid_voltage` | DC bus mid voltage | numérique (VOLTAGE) | V | — |  |
| `battery_mid_voltage_deviation` | DC bus mid voltage deviation | numérique | % | — |  |
| `battery_min_cell_temperature` | Minimum cell temperature | numérique (TEMPERATURE) | °C | — |  |
| `battery_min_cell_voltage` | Minimum cell voltage | numérique (VOLTAGE) | V | — |  |
| `battery_min_temperature_cell_id` | Minimum temperature cell ID | texte |  | — |  |
| `battery_min_voltage_cell_id` | Minimum voltage cell ID | texte |  | — |  |
| `battery_minimum_voltage` | Minimum voltage | numérique (VOLTAGE) | V | — |  |
| `battery_nr_modules_blocking_charge` | Number of modules blocking charge | numérique | modules | — |  |
| `battery_nr_modules_blocking_discharge` | Number of modules blocking discharge | numérique | modules | — |  |
| `battery_nr_modules_offline` | Number of modules offline | numérique | modules | — |  |
| `battery_nr_modules_online` | Number of modules online | numérique | modules | — |  |
| `battery_power` | Power | numérique (POWER) | W | — |  |
| `battery_soc` | Charge | numérique (BATTERY) | % | — |  |
| `battery_soh` | State of health | numérique | % | — |  |
| `battery_state_of_health_alarm` | State of health alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `battery_temperature` | Temperature | numérique (TEMPERATURE) | °C | — |  |
| `battery_time_since_last_full_charge` | Time since last full charge | numérique | s | — |  |
| `battery_time_to_go` | Time to go | numérique | s | — |  |
| `battery_total_charge_cycles` | Total charge cycles | numérique | cycles | — |  |
| `battery_voltage` | DC bus voltage | numérique (VOLTAGE) | V | — |  |
| `charge_mode` | Charge mode | texte |  | — |  |

### Compteur réseau (ET112/ET340/EM24…)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `grid_current` | Current | numérique | A | — |  |
| `grid_current_n` | Current on N | numérique | A | — |  |
| `grid_current_{phase}` | Current on {phase} | numérique | A | — |  |
| `grid_energy_forward` | Consumption | numérique (CONSUMPTION) | kWh | — |  |
| `grid_energy_forward_{phase}` | Grid consumption on {phase} | numérique (CONSUMPTION) | kWh | — |  |
| `grid_energy_reverse` | Feed-in | numérique (CONSUMPTION) | kWh | — |  |
| `grid_energy_reverse_{phase}` | Feed-in on {phase} | numérique (CONSUMPTION) | kWh | — |  |
| `grid_frequency` | Frequency | numérique | Hz | — |  |
| `grid_power` | Power | numérique (POWER) | W | — |  |
| `grid_power_factor` | Power factor | numérique |  | — |  |
| `grid_power_factor_{phase}` | Power factor on {phase} | numérique |  | — |  |
| `grid_power_{phase}` | Power on {phase} | numérique (POWER) | W | — |  |
| `grid_voltage` | Voltage | numérique (VOLTAGE) | V | — |  |
| `grid_voltage_pen` | Voltage on PEN | numérique (VOLTAGE) | V | — |  |
| `grid_voltage_{phase}` | Voltage on {phase} | numérique (VOLTAGE) | V | — |  |
| `grid_voltage_{phase}_{next_phase}` | Voltage {phase} to {next_phase} | numérique (VOLTAGE) | V | — |  |

### Charge AC (compteur de consommation)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `acload_current` | Load current | numérique | A | — |  |
| `acload_current_{phase}` | Current on {phase} | numérique | A | — |  |
| `acload_energy_forward` | Consumption | numérique (CONSUMPTION) | kWh | — |  |
| `acload_energy_forward_{phase}` | Consumption on {phase} | numérique (CONSUMPTION) | kWh | — |  |
| `acload_frequency` | Frequency | numérique | Hz | — |  |
| `acload_power` | Power | numérique (POWER) | W | — |  |
| `acload_power_{phase}` | Power on {phase} | numérique (POWER) | W | — |  |
| `acload_voltage` | Voltage | numérique (VOLTAGE) | V | — |  |
| `acload_voltage_{phase}` | Voltage on {phase} | numérique (VOLTAGE) | V | — |  |

### Charge DC

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `dcload_current` | Current | numérique | A | — |  |
| `dcload_power` | Power | numérique (POWER) | W | — |  |
| `dcload_voltage` | Voltage | numérique (VOLTAGE) | V | — |  |

### Source DC

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `dcsource_current` | Current | numérique | A | — |  |
| `dcsource_power` | Power | numérique (POWER) | W | — |  |
| `dcsource_temperature` | Temperature | numérique (TEMPERATURE) | °C | — |  |
| `dcsource_voltage` | Voltage | numérique (VOLTAGE) | V | — |  |

### Système DC

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `dcsystem_aux_voltage` | Auxiliary voltage | numérique (VOLTAGE) | V | — |  |
| `dcsystem_current` | Current | numérique | A | — |  |
| `dcsystem_power` | Power | numérique (POWER) | W | — |  |
| `dcsystem_voltage` | Voltage | numérique (VOLTAGE) | V | — |  |

### Chargeur DC/DC (Orion XS en mode batterie-batterie)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `dcdc_dc_current` | DC output current | numérique | A | — |  |
| `dcdc_dc_power` | DC output power | numérique (POWER) | W | — |  |
| `dcdc_dc_voltage` | DC output voltage | numérique (VOLTAGE) | V | — |  |
| `dcdc_input_current` | Input current | numérique | A | — |  |
| `dcdc_input_power` | Input power | numérique (POWER) | W | — |  |
| `dcdc_input_voltage` | Input voltage | numérique (VOLTAGE) | V | — |  |
| `dcdc_mode` | Mode | binaire |  | `1` = On, `0` = Off | `dcdc_mode_on` « Mode On », `dcdc_mode_off` « Mode Off » |
| `dcdc_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |

### Chargeur d'alternateur (Orion XS / Wakespeed…)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `alternator_charge_current_limit` | Charge current limit | numérique | A | Plage : 0 → selon l'appareil, ajustée selon l'appareil | `alternator_charge_current_limit_set` curseur « Régler Charge current limit » |
| `alternator_dc_current` | DC output current | numérique | A | — |  |
| `alternator_dc_power` | DC output power | numérique (POWER) | W | — |  |
| `alternator_dc_voltage` | DC output voltage | numérique (VOLTAGE) | V | — |  |
| `alternator_input_current` | Input current | numérique | A | — |  |
| `alternator_input_power` | Input power | numérique (POWER) | W | — |  |
| `alternator_input_voltage` | Input voltage | numérique (VOLTAGE) | V | — |  |
| `alternator_mode` | Mode | binaire |  | `1` = On, `0` = Off | `alternator_mode_on` « Mode On », `alternator_mode_off` « Mode Off » |
| `alternator_state` | State | texte |  | 22 valeurs → [liste State](#liste-state) |  |

### Générateur (démarrage/arrêt auto)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `generator_autorun` | Auto-start enabled | binaire |  | `1` = On, `0` = Off | `generator_autorun_on` « Auto-start enabled On », `generator_autorun_off` « Auto-start enabled Off » |
| `generator_manual_start` | Manual start | binaire |  | `1` = On, `0` = Off | `generator_manual_start_on` « Manual start On », `generator_manual_start_off` « Manual start Off » |
| `generator_next_test_run` | Next test run | texte |  | Date `AAAA-MM-JJ HH:MM:SS` |  |
| `generator_run_state` | Run state | texte |  | 11 valeurs → [liste GeneratorRunningByConditionCode](#liste-generatorrunningbyconditioncode) |  |
| `generator_service_counter` | Service counter | numérique | h | — |  |
| `generator_today_runtime` | Today runtime | numérique | h | — |  |
| `generator_total_runtime` | Total runtime | numérique | h | — |  |

### Réglages générateur 0 / 1

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `generator_{gen_id}_cool_down_timer` | Generator cooldown timer | numérique | s | Plage : 0 → 600 | `generator_{gen_id}_cool_down_timer_set` curseur « Régler Generator cooldown timer » |
| `generator_{gen_id}_qh_start_on_soc` | Generator QH start on SoC | numérique (BATTERY) | % | Plage : 0 → 100, pas 1, ajustée selon l'appareil | `generator_{gen_id}_qh_start_on_soc_set` curseur « Régler Generator QH start on SoC » |
| `generator_{gen_id}_qh_start_on_voltage` | Generator QH start on voltage | numérique (VOLTAGE) | V | Plage : pas 0.1, ajustée selon l'appareil | `generator_{gen_id}_qh_start_on_voltage_set` curseur « Régler Generator QH start on voltage » |
| `generator_{gen_id}_qh_stop_on_soc` | Generator QH stop on SoC | numérique (BATTERY) | % | Plage : 0 → 100, pas 1, ajustée selon l'appareil | `generator_{gen_id}_qh_stop_on_soc_set` curseur « Régler Generator QH stop on SoC » |
| `generator_{gen_id}_qh_stop_on_voltage` | Generator QH stop on voltage | numérique (VOLTAGE) | V | Plage : pas 0.1, ajustée selon l'appareil | `generator_{gen_id}_qh_stop_on_voltage_set` curseur « Régler Generator QH stop on voltage » |
| `generator_{gen_id}_quiet_hours_enabled` | Generator quiet hours enabled | binaire |  | `1` = On, `0` = Off | `generator_{gen_id}_quiet_hours_enabled_on` « Generator quiet hours enabled On », `generator_{gen_id}_quiet_hours_enabled_off` « Generator quiet hours enabled Off » |
| `generator_{gen_id}_service_interval` | Generator service interval | numérique | h | Plage : 0 → 500 | `generator_{gen_id}_service_interval_set` curseur « Régler Generator service interval » |
| `generator_{gen_id}_shut_down_timer` | Generator shutdown timer | numérique | s | Plage : 0 → 600 | `generator_{gen_id}_shut_down_timer_set` curseur « Régler Generator shutdown timer » |
| `generator_{gen_id}_start_on_soc` | Generator start on SoC | numérique (BATTERY) | % | Plage : 0 → 100, pas 1, ajustée selon l'appareil | `generator_{gen_id}_start_on_soc_set` curseur « Régler Generator start on SoC » |
| `generator_{gen_id}_start_on_soc_enabled` | Generator start on SoC enabled | binaire |  | `1` = On, `0` = Off | `generator_{gen_id}_start_on_soc_enabled_on` « Generator start on SoC enabled On », `generator_{gen_id}_start_on_soc_enabled_off` « Generator start on SoC enabled Off » |
| `generator_{gen_id}_start_on_soc_timer` | Generator start on SoC timer | numérique | s | Plage : 0 → 10000 | `generator_{gen_id}_start_on_soc_timer_set` curseur « Régler Generator start on SoC timer » |
| `generator_{gen_id}_start_on_temp_enabled` | Generator start on high temp enabled | binaire |  | `1` = On, `0` = Off | `generator_{gen_id}_start_on_temp_enabled_on` « Generator start on high temp enabled On », `generator_{gen_id}_start_on_temp_enabled_off` « Generator start on high temp enabled Off » |
| `generator_{gen_id}_start_on_temp_timer` | Generator start on temp timer | numérique | s | Plage : ajustée selon l'appareil | `generator_{gen_id}_start_on_temp_timer_set` curseur « Régler Generator start on temp timer » |
| `generator_{gen_id}_start_on_voltage` | Generator start on voltage | numérique (VOLTAGE) | V | Plage : pas 0.1, ajustée selon l'appareil | `generator_{gen_id}_start_on_voltage_set` curseur « Régler Generator start on voltage » |
| `generator_{gen_id}_start_on_voltage_enabled` | Generator start on voltage enabled | binaire |  | `1` = On, `0` = Off | `generator_{gen_id}_start_on_voltage_enabled_on` « Generator start on voltage enabled On », `generator_{gen_id}_start_on_voltage_enabled_off` « Generator start on voltage enabled Off » |
| `generator_{gen_id}_start_on_voltage_timer` | Generator start on voltage timer | numérique | s | Plage : ajustée selon l'appareil | `generator_{gen_id}_start_on_voltage_timer_set` curseur « Régler Generator start on voltage timer » |
| `generator_{gen_id}_stop_on_soc` | Generator stop on SoC | numérique (BATTERY) | % | Plage : 0 → 100, pas 1, ajustée selon l'appareil | `generator_{gen_id}_stop_on_soc_set` curseur « Régler Generator stop on SoC » |
| `generator_{gen_id}_stop_on_soc_timer` | Generator stop on SoC timer | numérique | s | Plage : 0 → 10000 | `generator_{gen_id}_stop_on_soc_timer_set` curseur « Régler Generator stop on SoC timer » |
| `generator_{gen_id}_stop_on_temp_timer` | Generator stop on temp timer | numérique | s | Plage : ajustée selon l'appareil | `generator_{gen_id}_stop_on_temp_timer_set` curseur « Régler Generator stop on temp timer » |
| `generator_{gen_id}_stop_on_voltage` | Generator stop on voltage | numérique (VOLTAGE) | V | Plage : pas 0.1, ajustée selon l'appareil | `generator_{gen_id}_stop_on_voltage_set` curseur « Régler Generator stop on voltage » |
| `generator_{gen_id}_stop_on_voltage_timer` | Generator stop on voltage timer | numérique | s | Plage : ajustée selon l'appareil | `generator_{gen_id}_stop_on_voltage_timer_set` curseur « Régler Generator stop on voltage timer » |
| `generator_{gen_id}_warm_up_timer` | Generator warm-up timer | numérique | s | Plage : 0 → 1800 | `generator_{gen_id}_warm_up_timer_set` curseur « Régler Generator warm-up timer » |

### Borne de recharge (EVCS)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `evcharger_auto_start` | Auto start | binaire |  | `1` = On, `0` = Off | `evcharger_auto_start_on` « Auto start On », `evcharger_auto_start_off` « Auto start Off » |
| `evcharger_charge` | EV charging | binaire |  | `1` = On, `0` = Off | `evcharger_charge_on` « EV charging On », `evcharger_charge_off` « EV charging Off » |
| `evcharger_connected` | Connected | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `evcharger_current` | Current | numérique | A | — |  |
| `evcharger_max_set_current` | Max charging current | numérique | A | Plage : selon l'appareil → 32, pas 1 | `evcharger_max_set_current_set` curseur « Régler Max charging current » |
| `evcharger_min_set_current` | Min charging current | numérique | A | Plage : 6 → selon l'appareil, pas 1 | `evcharger_min_set_current_set` curseur « Régler Min charging current » |
| `evcharger_mode` | Mode | texte |  | `Manual` · `Auto` · `Scheduled charge` | `evcharger_mode_set` liste « Choisir Mode » |
| `evcharger_position` | Position | texte |  | `AC out` · `AC input` |  |
| `evcharger_power` | Power | numérique (POWER) | W | — |  |
| `evcharger_power_{phase}` | Power {phase} | numérique (POWER) | W | — |  |
| `evcharger_session_cost` | Last session cost | numérique | € | — |  |
| `evcharger_session_energy` | Last session energy | numérique (CONSUMPTION) | kWh | — |  |
| `evcharger_session_time` | Last session time | numérique | min | — |  |
| `evcharger_set_current` | Charge current setpoint | numérique | A | Plage : selon l'appareil → selon l'appareil, pas 1 | `evcharger_set_current_set` curseur « Régler Charge current setpoint » |
| `evcharger_status` | Status | texte |  | 24 valeurs → [liste EvChargerStatus](#liste-evchargerstatus) |  |
| `evcharger_total_energy` | Total energy | numérique (CONSUMPTION) | kWh | — |  |

### Véhicule électrique

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `ev_ac_current` | AC current | numérique | A | — |  |
| `ev_ac_energy_forward` | Total energy | numérique (CONSUMPTION) | kWh | — |  |
| `ev_ac_max_charge_current` | Max charge current | numérique | A | — |  |
| `ev_ac_min_charge_current` | Min charge current | numérique | A | — |  |
| `ev_ac_nr_of_phases` | Number of phases | numérique |  | — |  |
| `ev_ac_power` | AC power | numérique (POWER) | W | — |  |
| `ev_ac_voltage` | AC voltage | numérique (VOLTAGE) | V | — |  |
| `ev_alarm_starter_battery_low` | Starter battery low | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `ev_at_site` | At site | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `ev_battery_capacity` | Battery capacity | numérique | Ah | — |  |
| `ev_battery_temperature` | Battery temperature | numérique (TEMPERATURE) | °C | — |  |
| `ev_charging_started` | Charging started | texte |  | Date `AAAA-MM-JJ HH:MM:SS` |  |
| `ev_charging_state` | Charging state | texte |  | `Not charging` · `Low power mode` · `Charging` · `Sustain` · `Wake up` · `Blocked` · `Unavailable` · `Discharging` · `Scheduled charging` |  |
| `ev_dc_current` | DC current | numérique | A | — |  |
| `ev_dc_power` | DC power | numérique (POWER) | W | — |  |
| `ev_dc_voltage` | DC voltage | numérique (VOLTAGE) | V | — |  |
| `ev_gps_location` | Location | texte (calculée) |  | `latitude,longitude` |  |
| `ev_last_ev_contact` | Last EV contact | texte |  | Date `AAAA-MM-JJ HH:MM:SS` |  |
| `ev_odometer` | Odometer | numérique | km | — |  |
| `ev_range_to_go` | Range to go | numérique | km | — |  |
| `ev_soc` | State of charge | numérique (BATTERY) | % | — |  |
| `ev_target_soc` | Target state of charge | numérique (BATTERY) | % | — |  |
| `ev_vin` | VIN | texte |  | — |  |

### Pompe à chaleur

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `heatpump_current` | Current | numérique | A | — |  |
| `heatpump_current_{phase}` | Current on {phase} | numérique | A | — |  |
| `heatpump_energy_forward` | Consumption | numérique (CONSUMPTION) | kWh | — |  |
| `heatpump_energy_forward_{phase}` | Consumption on {phase} | numérique (CONSUMPTION) | kWh | — |  |
| `heatpump_frequency` | Frequency | numérique | Hz | — |  |
| `heatpump_power` | Power | numérique (POWER) | W | — |  |
| `heatpump_power_{phase}` | Power on {phase} | numérique (POWER) | W | — |  |
| `heatpump_voltage` | Voltage | numérique (VOLTAGE) | V | — |  |
| `heatpump_voltage_{phase}` | Voltage on {phase} | numérique (VOLTAGE) | V | — |  |

### Réservoir (niveau de liquide)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `tank_battery_voltage` | Sensor battery voltage | numérique (VOLTAGE) | V | — |  |
| `tank_fluid_type` | Fluid type | texte |  | 12 valeurs → [liste FluidType](#liste-fluidtype) |  |
| `tank_level` | Level | numérique | % | — |  |
| `tank_remaining` | Remaining | numérique | L | — |  |
| `tank_temperature` | Temperature | numérique (TEMPERATURE) | °C | — |  |

### Sonde de température

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `temperature_battery_voltage` | Sensor battery voltage | numérique (VOLTAGE) | V | — |  |
| `temperature_humidity` | Humidity | numérique (HUMIDITY) | % | — |  |
| `temperature_offset` | Offset | numérique (TEMPERATURE) | °C | Plage : pas 1 | `temperature_offset_set` curseur « Régler Offset » |
| `temperature_pressure` | Pressure | numérique (PRESSURE) | hPa | — |  |
| `temperature_scale` | Scale factor | numérique |  | Plage : pas 0.1 | `temperature_scale_set` curseur « Régler Scale factor » |
| `temperature_status` | Sensor status | texte |  | `Ok` · `Disconnected` · `Short circuited` · `Reverse polarity` · `Unknown` |  |
| `temperature_temperature` | Temperature | numérique (TEMPERATURE) | °C | — |  |
| `temperature_type` | Sensor type | texte |  | `Battery` · `Fridge` · `Generic` · `Room` · `Outdoor` · `Water heater` · `Freezer` |  |

### Capteur d'irradiance (météo)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `meteo_alarm_low_battery` | Low battery alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `meteo_battery_voltage` | Battery voltage | numérique (VOLTAGE) | V | — |  |
| `meteo_cell_temperature` | Cell temperature | numérique (TEMPERATURE) | °C | — |  |
| `meteo_installation_power` | Installation power | numérique (POWER) | W | — |  |
| `meteo_irradiance` | Irradiance | numérique | W/m² | — |  |
| `meteo_time_since_last_sun` | Time since last sun | numérique | s | — |  |
| `meteo_todays_yield` | Today's yield | numérique (CONSUMPTION) | kWh | — |  |

### Entrée numérique

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `digitalinput_alarm` | Alarm | texte |  | `No alarm` · `Warning` · `Alarm` |  |
| `digitalinput_input_state_raw` | Raw state | texte |  | `High/open` · `Low/closed` |  |
| `digitalinput_settings_invert_translation` | Invert digital input | binaire |  | `1` = On, `0` = Off | `digitalinput_settings_invert_translation_on` « Invert digital input On », `digitalinput_settings_invert_translation_off` « Invert digital input Off » |
| `digitalinput_state` | State | texte |  | 12 valeurs → [liste DigitalInputState](#liste-digitalinputstate) |  |
| `digitalinput_type` | Type | texte |  | 11 valeurs → [liste DigitalInputType](#liste-digitalinputtype) |  |

### Sorties commutables (SwitchableOutput / Energy Solutions)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `switch_{output}_dimming` | Dimming | numérique | switch_{output}_unit:% | Plage : selon l'appareil → selon l'appareil, pas selon l'appareil | `switch_{output}_dimming_set` curseur « Régler Dimming » |
| `switch_{output}_state` | State | binaire |  | `1` = On, `0` = Off (interrupteur) — ou libellé choisi si la sortie est de type liste | `switch_{output}_state_on` / `switch_{output}_state_off` (ou `switch_{output}_state_set` liste) |

### GPS

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `gps_connected` | Connected | binaire |  | `1` = actif / On, `0` = inactif / Off |  |
| `gps_location` | Location | texte (calculée) |  | `latitude,longitude` |  |
| `gps_nrofsatellites` | Number of satellites | numérique | satellites | — |  |

### Commutateur de transfert (réglages)

| Logical ID | Nom par défaut | Info Jeedom | Unité | Valeurs possibles / plage | Action(s) créée(s) |
|---|---|---|---|---|---|
| `transfer_switch_generator_current_limit` | Generator AC current limit | numérique | A | Plage : 0 → 60 | `transfer_switch_generator_current_limit_set` curseur « Régler Generator AC current limit » |

---

## 11. Annexe : listes de valeurs longues

Listes des commandes info qui ont plus de 10 valeurs possibles. La colonne de gauche donne le code publié par le GX, la colonne de droite le texte exact contenu dans l'info Jeedom.

<a id="liste-chargeschedule"></a>
### Liste ChargeSchedule

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| -10 | `Disabled (Sunday)` |
| -9 | `Disabled (Weekends)` |
| -8 | `Disabled (Weekdays)` |
| -7 | `Disabled (Every day)` |
| -6 | `Disabled (Saturday)` |
| -5 | `Disabled (Friday)` |
| -4 | `Disabled (Thursday)` |
| -3 | `Disabled (Wednesday)` |
| -2 | `Disabled (Tuesday)` |
| -1 | `Disabled (Monday)` |
| 0 | `Sunday` |
| 1 | `Monday` |
| 2 | `Tuesday` |
| 3 | `Wednesday` |
| 4 | `Thursday` |
| 5 | `Friday` |
| 6 | `Saturday` |
| 7 | `Every day` |
| 8 | `Weekdays` |
| 9 | `Weekends` |

<a id="liste-dessreactivestrategy"></a>
### Liste DESSReactiveStrategy

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 1 | `Scheduled self-consume` |
| 2 | `Scheduled charge allow grid` |
| 3 | `Scheduled charge enhanced` |
| 4 | `Self-consume accept charge` |
| 5 | `Idle scheduled feed-in` |
| 6 | `Scheduled discharge` |
| 7 | `Self-consume accept discharge` |
| 8 | `Idle maintain surplus` |
| 9 | `Idle maintain target SoC` |
| 10 | `Scheduled charge smooth transition` |
| 11 | `Scheduled charge feed-in` |
| 12 | `Scheduled charge no grid` |
| 13 | `Scheduled minimum discharge` |
| 14 | `Self-consume no grid` |
| 15 | `Idle no opportunity` |
| 16 | `Unscheduled charge catch-up target SoC` |
| 17 | `Self-consume increased discharge` |
| 18 | `Keep battery charged` |
| 19 | `Scheduled discharge smooth transition` |
| 92 | `DESS disabled` |
| 93 | `Self-consume unexpected exception` |
| 94 | `Self-consume faulty charge rate` |
| 95 | `Unknown operating mode` |
| 96 | `ESS low SoC` |
| 97 | `Self-consume unmapped state` |
| 98 | `Self-consume unpredicted` |
| 99 | `No window` |

<a id="liste-digitalinputstate"></a>
### Liste DigitalInputState

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `Low` |
| 1 | `High` |
| 2 | `Off` |
| 3 | `On` |
| 4 | `No` |
| 5 | `Yes` |
| 6 | `Open` |
| 7 | `Closed` |
| 8 | `Ok` |
| 9 | `Alarm` |
| 10 | `Running` |
| 11 | `Stopped` |

<a id="liste-digitalinputtype"></a>
### Liste DigitalInputType

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `Disabled` |
| 1 | `Pulse meter` |
| 2 | `Door alarm` |
| 3 | `Bilge pump` |
| 4 | `Bilge alarm` |
| 5 | `Burglar alarm` |
| 6 | `Smoke alarm` |
| 7 | `Fire alarm` |
| 8 | `CO2 alarm` |
| 9 | `Generator` |
| 10 | `Touch input control` |

<a id="liste-essstate"></a>
### Liste ESSState

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 1 | `Optimized mode with BatteryLife` |
| 2 | `Self-consumption` |
| 3 | `Self-consumption, SoC exceeds 85%` |
| 4 | `Self-consumption, SoC at 100%` |
| 5 | `SoC below BatteryLife dynamic SoC limit` |
| 6 | `SoC has been below SoC limit for more than 24 hours. Charging battery with 5 amps` |
| 7 | `Multi/Quattro is in sustain` |
| 8 | `Recharge, SoC dropped 5% or more below minimum SoC` |
| 9 | `'Keep batteries charged' mode enabled` |
| 10 | `Self-consumption, SoC at or above minimum SoC` |
| 11 | `Self-consumption, SoC is below minimum SoC` |
| 12 | `Recharge, SoC dropped 5% or more below minimum SoC (No BatteryLife)` |

<a id="liste-errorcode"></a>
### Liste ErrorCode

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `No error` |
| 2 | `Battery voltage too high` |
| 17 | `Charger temperature too high` |
| 18 | `Charger over current` |
| 19 | `Charger current reversed` |
| 20 | `Bulk time limit exceeded` |
| 21 | `Current sensor issue` |
| 26 | `Terminals overheated` |
| 28 | `Converter issue` |
| 33 | `Input voltage too high (solar panel)` |
| 34 | `Input current too high (solar panel)` |
| 38 | `Input shutdown (battery voltage too high)` |
| 39 | `Input shutdown (reverse current)` |
| 65 | `Lost communication with device` |
| 66 | `Synchronized charging config issue` |
| 67 | `BMS connection lost` |
| 68 | `Network misconfigured` |
| 116 | `Factory calibration data lost` |
| 117 | `Invalid/incompatible firmware` |
| 119 | `User settings invalid` |

<a id="liste-evchargerstatus"></a>
### Liste EvChargerStatus

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `Disconnected` |
| 1 | `Connected` |
| 2 | `Charging` |
| 3 | `Charged` |
| 4 | `Waiting for sun` |
| 5 | `Waiting for RFID` |
| 6 | `Waiting for start` |
| 7 | `Low SoC` |
| 8 | `Ground test error` |
| 9 | `Welded contacts test error` |
| 10 | `CP input test error` |
| 11 | `Residual current detected` |
| 12 | `Undervoltage detected` |
| 13 | `Overvoltage detected` |
| 14 | `Overheating detected` |
| 15 | `Reserved` |
| 16 | `Reserved` |
| 17 | `Reserved` |
| 18 | `Reserved` |
| 19 | `Reserved` |
| 20 | `Charging limit` |
| 21 | `Start charging` |
| 22 | `Switching to 3 phase` |
| 23 | `Switching to 1 phase` |

<a id="liste-fluidtype"></a>
### Liste FluidType

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `Fuel` |
| 1 | `Fresh water` |
| 2 | `Waste water` |
| 3 | `Live well` |
| 4 | `Oil` |
| 5 | `Black water (sewage)` |
| 6 | `Gasoline` |
| 7 | `Diesel` |
| 8 | `Liquid petroleum gas (LPG)` |
| 9 | `Liquid natural gas (LNG)` |
| 10 | `Hydraulic oil` |
| 11 | `Raw water` |

<a id="liste-generatorrunningbyconditioncode"></a>
### Liste GeneratorRunningByConditionCode

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `Stopped` |
| 1 | `Manual` |
| 2 | `Test run` |
| 3 | `Lost comms` |
| 4 | `SoC` |
| 5 | `AC load` |
| 6 | `Battery current` |
| 7 | `Battery volts` |
| 8 | `Inverter temperature` |
| 9 | `Inverter overload` |
| 10 | `Stop on AC1` |

<a id="liste-solarchargerdeviceoffreason"></a>
### Liste SolarChargerDeviceOffReason

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `-` |
| 1 | `No/low input power` |
| 2 | `Switched off (power switch)` |
| 4 | `Switched off (device mode register)` |
| 8 | `Remote input` |
| 16 | `Protection active` |
| 32 | `Need token for operation` |
| 64 | `Signal from BMS` |
| 128 | `Engine shutdown on low input voltage` |
| 256 | `Analysing input voltage` |
| 512 | `Low temperature` |
| 1024 | `No/low panel power` |
| 2048 | `No/low battery power` |
| 32768 | `Active alarm` |

<a id="liste-state"></a>
### Liste State

| Code GX | Valeur affichée dans Jeedom |
|---|---|
| 0 | `Off` |
| 1 | `Low power` |
| 2 | `Fault` |
| 3 | `Bulk` |
| 4 | `Absorption` |
| 5 | `Float` |
| 6 | `Storage` |
| 7 | `Equalize` |
| 8 | `Passthrough` |
| 9 | `Inverting` |
| 10 | `Power Assist` |
| 11 | `Power supply` |
| 244 | `Sustain` |
| 245 | `Starting up` |
| 246 | `Repeated absorption` |
| 247 | `Auto equalize / recondition` |
| 248 | `Battery Safe` |
| 252 | `External control` |
| 256 | `Discharging` |
| 257 | `Sustain alt` |
| 258 | `Recharging` |
| 259 | `Scheduled recharging` |

---

## 12. Changelog

### 1.0 — 12/10/2026

- Première version : connexion directe au MQTT du GX (librairie `victron-mqtt` 2026.9.5, comme l'intégration Home Assistant `victron_gx`).
- Découverte et création automatiques des équipements et commandes (capteurs, binaires, interrupteurs, consignes, listes, heures, boutons, GPS).
- Connexion en clair, TLS avec mot de passe du GX, ou appairage par jeton.
- Installation des dépendances via `dependance.lib` / `pyenv.lib` : Debian 11 (Python 3.11 compilé par PyEnv) et Debian 12.
- Correctif : la méthode `getStatus()` entrait en conflit avec celle du cœur Jeedom et empêchait l'activation du plugin. Elle est renommée `getGxStatus()`.
