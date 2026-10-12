<?php
/* This file is part of Jeedom - plugin Victron GX
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class victron extends eqLogic {

    /* Types de valeurs numériques côté victron-mqtt */
    const NUMERIC_VALUE_TYPES = array('int', 'int_0', 'float', 'int_seconds_to_hours', 'int_seconds_to_minutes', 'float_m3_to_liters');
    /* Types de métriques numériques (repli si value_type absent) */
    const NUMERIC_METRIC_TYPES = array('power', 'apparent_power', 'energy', 'voltage', 'current', 'temperature', 'humidity', 'pressure',
        'distance', 'frequency', 'power_factor', 'duration', 'percentage', 'electric_storage_capacity', 'electric_storage_percentage',
        'liquid_volume', 'latitude', 'longitude', 'altitude', 'heading', 'speed', 'cost', 'irradiance');
    /* Types génériques Jeedom */
    const GENERIC_TYPES = array(
        'power' => 'POWER',
        'energy' => 'CONSUMPTION',
        'voltage' => 'VOLTAGE',
        'temperature' => 'TEMPERATURE',
        'humidity' => 'HUMIDITY',
        'pressure' => 'PRESSURE',
        'electric_storage_percentage' => 'BATTERY',
    );
    /* Types historisés automatiquement si l'option est active */
    const AUTO_HISTORIZE_TYPES = array('power', 'energy', 'electric_storage_percentage', 'voltage', 'current', 'temperature');

    private static $_eqCache = array();

    /*     * ************************ Dépendances ************************* */

    public static function dependancy_info() {
        $return = array();
        $return['log'] = log::getPathToLog(__CLASS__ . '_update');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
        $return['state'] = 'nok';
        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
            return $return;
        }
        $python = self::getPython();
        if (is_executable($python)) {
            $out = array();
            $code = 1;
            exec(escapeshellarg($python) . " -c 'import victron_mqtt' 2>&1", $out, $code);
            if ($code == 0) {
                $return['state'] = 'ok';
            }
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove(__CLASS__ . '_update');
        return array(
            'script' => __DIR__ . '/../../resources/install.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependance',
            'log' => log::getPathToLog(__CLASS__ . '_update'),
        );
    }

    public static function getPython() {
        return realpath(__DIR__ . '/../../resources') . '/venv/bin/python3';
    }

    /*     * ************************ Démon ************************* */

    public static function deamon_info() {
        $return = array();
        $return['log'] = __CLASS__ . '_daemon';
        $return['state'] = 'nok';
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0 && @posix_getsid($pid)) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
            }
        }
        $return['launchable'] = 'ok';
        if (trim(config::byKey('host', __CLASS__)) == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('L\'adresse du GX n\'est pas configurée', __FILE__);
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }

        $path = realpath(__DIR__ . '/../../resources/victrond');
        $callback = network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/victron/core/php/jeeVictron.php';
        $args = array(
            '--loglevel' => log::convertLogLevel(log::getLogLevel(__CLASS__)),
            '--socketport' => config::byKey('socketport', __CLASS__, 55090),
            '--callback' => $callback,
            '--apikey' => jeedom::getApiKey(__CLASS__),
            '--pid' => jeedom::getTmpFolder(__CLASS__) . '/daemon.pid',
            '--host' => trim(config::byKey('host', __CLASS__)),
            '--port' => config::byKey('port', __CLASS__, 1883),
            '--ssl' => config::byKey('ssl', __CLASS__, 0) ? '1' : '0',
            '--installation_id' => trim(config::byKey('installation_id_forced', __CLASS__, '')),
            '--frequency' => config::byKey('frequency', __CLASS__, 'auto'),
            '--readonly' => config::byKey('readonly', __CLASS__, 0) ? '1' : '0',
            '--cycle' => config::byKey('cycle', __CLASS__, 1),
        );
        $cmd = '';
        foreach ($args as $key => $value) {
            $cmd .= ' ' . $key . ' ' . escapeshellarg((string) $value);
        }
        $cmd = escapeshellarg(self::getPython()) . ' ' . escapeshellarg($path . '/victrond.py') . $cmd;
        log::add(__CLASS__, 'info', 'Lancement du démon : ' . str_replace(jeedom::getApiKey(__CLASS__), '***', $cmd));

        // Identifiants passés par variables d'environnement (non visibles dans ps)
        $env = 'VICTRON_USERNAME=' . escapeshellarg((string) config::byKey('username', __CLASS__, ''))
            . ' VICTRON_PASSWORD=' . escapeshellarg((string) config::byKey('password', __CLASS__, ''));
        exec($env . ' ' . $cmd . ' >> ' . log::getPathToLog(__CLASS__ . '_daemon') . ' 2>&1 &');

        $i = 0;
        while ($i < 20) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 20) {
            log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log victron_daemon', __FILE__), 'unableStartDeamon');
            return false;
        }
        message::removeAll(__CLASS__, 'unableStartDeamon');
        return true;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0) {
                posix_kill($pid, 15);
                for ($i = 0; $i < 10 && @posix_getsid($pid); $i++) {
                    usleep(500000);
                }
                if (@posix_getsid($pid)) {
                    posix_kill($pid, 9);
                }
            }
            @unlink($pid_file);
        }
        system::kill('victrond.py');
        system::fuserk(config::byKey('socketport', __CLASS__, 55090));
        cache::set('victron::status', json_encode(array('connected' => false)));
    }

    public static function sendToDaemon($params) {
        $deamon_info = self::deamon_info();
        if ($deamon_info['state'] != 'ok') {
            throw new Exception(__('Le démon Victron n\'est pas démarré', __FILE__));
        }
        $params['apikey'] = jeedom::getApiKey(__CLASS__);
        $payload = json_encode($params);
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if ($socket === false || !@socket_connect($socket, '127.0.0.1', config::byKey('socketport', __CLASS__, 55090))) {
            throw new Exception(__('Impossible de joindre le démon Victron', __FILE__));
        }
        socket_write($socket, $payload, strlen($payload));
        socket_close($socket);
    }

    public static function getGxStatus() {
        $status = json_decode(cache::byKey('victron::status')->getValue('{}'), true);
        return is_array($status) ? $status : array();
    }

    /*     * ************************ Données reçues du démon ************************* */

    public static function handleData($data) {
        if (!is_array($data)) {
            return;
        }
        if (!empty($data['installation_id']) && config::byKey('installation_id', __CLASS__) != $data['installation_id']) {
            config::save('installation_id', $data['installation_id'], __CLASS__);
        }
        if (isset($data['devices']) && is_array($data['devices'])) {
            foreach ($data['devices'] as $device) {
                self::syncDevice($device);
            }
        }
        if (isset($data['metrics']) && is_array($data['metrics'])) {
            foreach ($data['metrics'] as $definition) {
                try {
                    self::syncMetric($definition);
                } catch (Exception $e) {
                    log::add(__CLASS__, 'error', 'Création de commande ' . json_encode($definition) . ' : ' . $e->getMessage());
                }
            }
        }
        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $uid => $values) {
                $eqLogic = self::getEqByUid($uid);
                if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
                    continue;
                }
                foreach ($values as $id => $value) {
                    $eqLogic->checkAndUpdateCmd($id, $value);
                }
            }
        }
        if (isset($data['status']) && is_array($data['status'])) {
            $status = array_merge(self::getGxStatus(), $data['status']);
            cache::set('victron::status', json_encode($status));
            log::add(__CLASS__, 'debug', 'Statut : ' . json_encode($status));
        }
        if (isset($data['messages']) && is_array($data['messages'])) {
            foreach ($data['messages'] as $msg) {
                $level = (isset($msg['level']) && $msg['level'] == 'error') ? 'error' : 'warning';
                log::add(__CLASS__, $level, $msg['text']);
            }
        }
    }

    public static function getEqByUid($uid) {
        if (!isset(self::$_eqCache[$uid])) {
            $eqLogic = eqLogic::byLogicalId($uid, __CLASS__);
            if (!is_object($eqLogic)) {
                return null;
            }
            self::$_eqCache[$uid] = $eqLogic;
        }
        return self::$_eqCache[$uid];
    }

    private static function syncDevice($device) {
        if (!isset($device['uid'])) {
            return;
        }
        $eqLogic = self::getEqByUid($device['uid']);
        if (!is_object($eqLogic)) {
            if (config::byKey('autocreate', __CLASS__, 1) != 1) {
                log::add(__CLASS__, 'debug', 'Nouvel appareil ignoré (création auto désactivée) : ' . $device['uid']);
                return;
            }
            log::add(__CLASS__, 'info', 'Création de l\'équipement ' . $device['name'] . ' (' . $device['uid'] . ')');
            $eqLogic = new victron();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId($device['uid']);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $objectId = config::byKey('defaultObject', __CLASS__, '');
            if ($objectId != '' && is_object(jeeObject::byId($objectId))) {
                $eqLogic->setObject_id($objectId);
            }
            $name = substr(trim($device['name']) != '' ? trim($device['name']) : $device['uid'], 0, 120);
            $eqLogic->setName($name);
            foreach (self::deviceConfiguration($device) as $key => $value) {
                $eqLogic->setConfiguration($key, $value);
            }
            try {
                $eqLogic->save();
            } catch (Exception $e) {
                // nom déjà utilisé dans l'objet
                $eqLogic->setName(substr($name, 0, 100) . ' - ' . $device['uid']);
                $eqLogic->save();
            }
            self::$_eqCache[$device['uid']] = $eqLogic;
            event::add('jeedom::alert', array('level' => 'success', 'page' => __CLASS__,
                'message' => __('Nouvel équipement Victron : ', __FILE__) . $eqLogic->getName()));
            return;
        }
        $changed = false;
        foreach (self::deviceConfiguration($device) as $key => $value) {
            if ($value !== '' && $eqLogic->getConfiguration($key) != $value) {
                $eqLogic->setConfiguration($key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            $eqLogic->save(true);
        }
    }

    private static function deviceConfiguration($device) {
        $conf = array();
        foreach (array('type', 'type_code', 'model', 'manufacturer', 'serial', 'firmware', 'product_id', 'device_id', 'parent') as $key) {
            $conf[$key] = isset($device[$key]) ? (string) $device[$key] : '';
        }
        return $conf;
    }

    private static function isNumeric($def) {
        if (in_array($def['kind'], array('time', 'device_tracker'))) {
            return false;
        }
        if (!empty($def['options']) || $def['type'] == 'enum' || $def['type'] == 'timestamp') {
            return false;
        }
        if (isset($def['value_type']) && $def['value_type'] != '') {
            return in_array($def['value_type'], self::NUMERIC_VALUE_TYPES);
        }
        return in_array($def['type'], self::NUMERIC_METRIC_TYPES) || $def['unit'] != '';
    }

    private static function syncMetric($def) {
        $eqLogic = self::getEqByUid($def['device']);
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            return;
        }
        $kind = $def['kind'];
        $id = $def['id'];
        $name = trim($def['name']) != '' ? trim($def['name']) : $id;
        $hidden = !empty($def['hidden']);
        $infoCmd = null;

        if ($kind != 'button') {
            if (in_array($kind, array('binary_sensor', 'switch'))) {
                $subType = 'binary';
            } elseif (self::isNumeric($def)) {
                $subType = 'numeric';
            } else {
                $subType = 'string';
            }
            $unit = ($subType == 'numeric') ? self::jeedomUnit($def) : '';
            $infoCmd = $eqLogic->getCmd('info', $id);
            if (!is_object($infoCmd)) {
                $infoCmd = new victronCmd();
                $infoCmd->setEqLogic_id($eqLogic->getId());
                $infoCmd->setLogicalId($id);
                $infoCmd->setType('info');
                $infoCmd->setSubType($subType);
                $infoCmd->setName(self::uniqueCmdName($eqLogic, $name, $id));
                $infoCmd->setUnite($unit);
                $infoCmd->setIsVisible($hidden ? 0 : 1);
                $infoCmd->setOrder(count($eqLogic->getCmd()));
                if (isset(self::GENERIC_TYPES[$def['type']]) && $subType == 'numeric') {
                    $infoCmd->setGeneric_type(self::GENERIC_TYPES[$def['type']]);
                }
                if ($subType == 'numeric' && !$hidden && config::byKey('autohistorize', __CLASS__, 0) == 1
                    && in_array($def['type'], self::AUTO_HISTORIZE_TYPES)) {
                    $infoCmd->setIsHistorized(1);
                }
                if ($subType == 'numeric' && isset($def['min']) && $def['min'] !== null) {
                    $infoCmd->setConfiguration('minValue', $def['min']);
                }
                if ($subType == 'numeric' && isset($def['max']) && $def['max'] !== null) {
                    $infoCmd->setConfiguration('maxValue', $def['max']);
                }
                if ($def['type'] == 'electric_storage_percentage' || $unit == '%') {
                    if ($infoCmd->getConfiguration('minValue') === '') $infoCmd->setConfiguration('minValue', 0);
                    if ($infoCmd->getConfiguration('maxValue') === '') $infoCmd->setConfiguration('maxValue', 100);
                }
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : création info ' . $id . ' (' . $subType . ')');
            }
            self::setMetricConfiguration($infoCmd, $def);
            $infoCmd->save();
        }

        switch ($kind) {
            case 'switch':
                self::createAction($eqLogic, $id . '_on', $name . ' On', 'other', $def, $infoCmd, $hidden, array('value' => 'on'));
                self::createAction($eqLogic, $id . '_off', $name . ' Off', 'other', $def, $infoCmd, $hidden, array('value' => 'off'));
                break;
            case 'number':
                $action = self::createAction($eqLogic, $id . '_set', 'Régler ' . $name, 'slider', $def, $infoCmd, $hidden);
                $changed = false;
                foreach (array('min' => 'minValue', 'max' => 'maxValue') as $src => $dst) {
                    if (isset($def[$src]) && $def[$src] !== null && $action->getConfiguration($dst) != $def[$src]) {
                        $action->setConfiguration($dst, $def[$src]);
                        $changed = true;
                    }
                }
                if (isset($def['step']) && $def['step'] !== null) {
                    $params = $action->getDisplay('parameters', array());
                    if (!is_array($params)) $params = array();
                    if (!isset($params['step']) || $params['step'] != $def['step']) {
                        $params['step'] = $def['step'];
                        $action->setDisplay('parameters', $params);
                        $changed = true;
                    }
                }
                $unit = self::jeedomUnit($def);
                if ($action->getUnite() != $unit) {
                    $action->setUnite($unit);
                    $changed = true;
                }
                if ($changed) $action->save();
                break;
            case 'select':
                $action = self::createAction($eqLogic, $id . '_set', 'Choisir ' . $name, 'select', $def, $infoCmd, $hidden);
                $list = array();
                if (isset($def['options']) && is_array($def['options'])) {
                    foreach ($def['options'] as $opt) {
                        $list[] = $opt['label'] . '|' . $opt['label'];
                    }
                }
                $listValue = implode(';', $list);
                if ($action->getConfiguration('listValue') != $listValue) {
                    $action->setConfiguration('listValue', $listValue);
                    $action->save();
                }
                break;
            case 'button':
                self::createAction($eqLogic, $id, $name, 'other', $def, null, $hidden);
                break;
            case 'time':
                $action = self::createAction($eqLogic, $id . '_set', 'Régler ' . $name, 'message', $def, $infoCmd, $hidden);
                if ($action->getDisplay('title_disable') != 1) {
                    $action->setDisplay('title_disable', 1);
                    $action->setDisplay('message_placeholder', 'HH:MM');
                    $action->save();
                }
                break;
        }
    }

    private static function createAction($eqLogic, $logicalId, $name, $subType, $def, $infoCmd, $hidden, $extraConf = array()) {
        $cmd = $eqLogic->getCmd('action', $logicalId);
        if (!is_object($cmd)) {
            $cmd = new victronCmd();
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setLogicalId($logicalId);
            $cmd->setType('action');
            $cmd->setSubType($subType);
            $cmd->setName(self::uniqueCmdName($eqLogic, $name, $logicalId));
            $cmd->setIsVisible($hidden ? 0 : 1);
            $cmd->setOrder(count($eqLogic->getCmd()));
            log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : création action ' . $logicalId . ' (' . $subType . ')');
        }
        self::setMetricConfiguration($cmd, $def);
        foreach ($extraConf as $key => $value) {
            $cmd->setConfiguration($key, $value);
        }
        if (is_object($infoCmd)) {
            $cmd->setValue($infoCmd->getId());
        }
        $cmd->save();
        return $cmd;
    }

    private static function setMetricConfiguration($cmd, $def) {
        $cmd->setConfiguration('metric', $def['id']);
        $cmd->setConfiguration('kind', $def['kind']);
        $cmd->setConfiguration('metric_type', $def['type']);
        if (isset($def['generic_id'])) {
            $cmd->setConfiguration('generic_id', $def['generic_id']);
        }
    }

    private static function uniqueCmdName($eqLogic, $name, $logicalId) {
        $name = substr(str_replace(array('#', '[', ']'), '', $name), 0, 120);
        $existing = cmd::byEqLogicIdCmdName($eqLogic->getId(), $name);
        if (is_object($existing) && $existing->getLogicalId() != $logicalId) {
            $name = substr($name, 0, 80) . ' (' . substr($logicalId, 0, 40) . ')';
        }
        return $name;
    }

    private static function jeedomUnit($def) {
        $unit = isset($def['unit']) ? (string) $def['unit'] : '';
        if ($def['type'] == 'cost' && $unit == '') {
            return '€';
        }
        return $unit;
    }

    /*     * ************************ Méthodes d'instance ************************* */

    public function getImage() {
        return 'plugins/victron/plugin_info/victron_icon.png';
    }

    public function syncCommands() {
        self::sendToDaemon(array('action' => 'sync', 'device' => $this->getLogicalId()));
    }
}

class victronCmd extends cmd {

    public function dontRemoveCmd() {
        return true;
    }

    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        $kind = $this->getConfiguration('kind');
        switch ($kind) {
            case 'switch':
                $value = $this->getConfiguration('value', 'on');
                break;
            case 'number':
                $value = isset($_options['slider']) ? $_options['slider'] : null;
                break;
            case 'select':
                $value = isset($_options['select']) ? $_options['select'] : null;
                break;
            case 'time':
                $value = isset($_options['message']) ? trim($_options['message']) : '';
                if ($value == '' && isset($_options['title'])) {
                    $value = trim($_options['title']);
                }
                break;
            case 'button':
                $value = 'on';
                break;
            default:
                throw new Exception(__('Type de commande inconnu : ', __FILE__) . $kind);
        }
        if ($value === null || $value === '') {
            throw new Exception(__('Aucune valeur fournie pour ', __FILE__) . $this->getHumanName());
        }
        log::add('victron', 'info', $this->getHumanName() . ' -> ' . $value);
        victron::sendToDaemon(array(
            'action' => 'set',
            'device' => $eqLogic->getLogicalId(),
            'metric' => $this->getConfiguration('metric'),
            'value' => $value,
        ));
    }
}
