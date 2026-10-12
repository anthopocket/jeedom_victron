<?php
/* This file is part of Jeedom - plugin Victron GX
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function victron_install() {
    $defaults = array(
        'host' => '',
        'port' => 1883,
        'ssl' => 0,
        'socketport' => 55090,
        'frequency' => 'auto',
        'cycle' => 1,
        'autocreate' => 1,
        'readonly' => 0,
    );
    foreach ($defaults as $key => $value) {
        if (config::byKey($key, 'victron', '') === '') {
            config::save($key, $value, 'victron');
        }
    }
}

function victron_update() {
    victron_install();
}

function victron_remove() {
}
