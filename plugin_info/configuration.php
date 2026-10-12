<?php
/* This file is part of Jeedom - plugin Victron GX */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-plug"></i> {{Connexion au GX}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Adresse du GX}}
                <sup><i class="fas fa-question-circle tooltips" title="{{IP ou nom du Cerbo / Venus GX / Ekrano (ex. 192.168.1.50 ou venus.local). Activez le MQTT sur le GX : Paramètres > Services > MQTT}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input class="configKey form-control" data-l1key="host" placeholder="venus.local"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port}}
                <sup><i class="fas fa-question-circle tooltips" title="{{1883 en clair, 8883 en SSL}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input class="configKey form-control" data-l1key="port" placeholder="1883"/>
            </div>
            <label class="col-md-1 control-label">{{SSL}}</label>
            <div class="col-md-1">
                <input type="checkbox" class="configKey" data-l1key="ssl"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Utilisateur}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Laisser vide sauf appairage par jeton (rempli automatiquement)}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input class="configKey form-control" data-l1key="username" autocomplete="off"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Mot de passe}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe du GX si le profil de sécurité réseau est « Sécurisé », sinon vide}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="password" class="configKey form-control" data-l1key="password" autocomplete="new-password"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Appairage par jeton}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Pour les Venus OS récents : activez le mode appairage sur le GX (Paramètres > Intégrations > Appareils MQTT > Mode appairage, ou double appui sur le bouton), puis cliquez}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input class="form-control" id="victron_pair_vrm" placeholder="{{ID VRM (optionnel)}}"/>
            </div>
            <div class="col-md-2">
                <a class="btn btn-warning" id="bt_victronPair"><i class="fas fa-link"></i> {{Appairer}}</a>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{État}}</label>
            <div class="col-md-6" id="victron_status" style="padding-top:7px;">
                <span class="label label-default">{{inconnu}}</span>
            </div>
        </div>

        <legend><i class="fas fa-cogs"></i> {{Fonctionnement}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Fréquence de mise à jour}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Auto (comme Home Assistant) : puissances/courants toutes les 5 s, le reste toutes les 30 s max}}"></i></sup>
            </label>
            <div class="col-md-4">
                <select class="configKey form-control" data-l1key="frequency">
                    <option value="auto">{{Auto (recommandé)}}</option>
                    <option value="auto_unthrottled">{{Auto sans limite (temps réel pour puissances/courants)}}</option>
                    <option value="change">{{À chaque changement}}</option>
                    <option value="10">{{10 s max}}</option>
                    <option value="30">{{30 s max}}</option>
                    <option value="60">{{60 s max}}</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Regroupement des envois (s)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Les valeurs reçues sont envoyées à Jeedom par lots toutes les X secondes}}"></i></sup>
            </label>
            <div class="col-md-2">
                <input class="configKey form-control" data-l1key="cycle" placeholder="1"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Création automatique des équipements}}</label>
            <div class="col-md-4">
                <input type="checkbox" class="configKey" data-l1key="autocreate"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Objet parent par défaut}}</label>
            <div class="col-md-4">
                <select class="configKey form-control" data-l1key="defaultObject">
                    <option value="">{{Aucun}}</option>
                    <?php
                    foreach ((jeeObject::buildTree(null, false)) as $object) {
                        echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Historiser automatiquement}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Historise à la création les puissances, énergies, SOC, tensions, courants et températures}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="checkbox" class="configKey" data-l1key="autohistorize"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Lecture seule}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Aucune commande d'écriture vers le GX (redémarrer le démon)}}"></i></sup>
            </label>
            <div class="col-md-4">
                <input type="checkbox" class="configKey" data-l1key="readonly"/>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port socket interne}}</label>
            <div class="col-md-2">
                <input class="configKey form-control" data-l1key="socketport" placeholder="55090"/>
            </div>
        </div>
    </fieldset>
</form>

<script>
    function victron_refreshStatus() {
        $.ajax({
            type: 'POST',
            url: 'plugins/victron/core/ajax/victron.ajax.php',
            data: {action: 'getStatus'},
            dataType: 'json',
            global: false,
            success: function (data) {
                if (data.state != 'ok') return;
                var s = data.result || {};
                var html = '';
                if (s.connected) {
                    html = '<span class="label label-success">{{Connecté}}</span>';
                } else if (s.error == 'auth') {
                    html = '<span class="label label-danger">{{Authentification refusée}}</span>';
                } else {
                    html = '<span class="label label-danger">{{Déconnecté}}</span>';
                }
                if (s.installation_id) html += ' &nbsp;{{Installation}} : <b>' + s.installation_id + '</b>';
                if (s.model) html += ' &nbsp;(' + s.model + ')';
                $('#victron_status').html(html);
            }
        });
    }
    victron_refreshStatus();

    $('#bt_victronPair').off('click').on('click', function () {
        var host = $('.configKey[data-l1key=host]').value();
        $.ajax({
            type: 'POST',
            url: 'plugins/victron/core/ajax/victron.ajax.php',
            data: {action: 'pair', host: host, vrm: $('#victron_pair_vrm').value()},
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state != 'ok') {
                    $.fn.showAlert({message: data.result, level: 'danger'});
                    return;
                }
                $.fn.showAlert({message: '{{Appairage réussi (jeton}} ' + data.result.username + '). {{Redémarrez le démon.}}', level: 'success'});
                $('.configKey[data-l1key=username]').value(data.result.username);
                $('.configKey[data-l1key=password]').value(data.result.password);
                $('.configKey[data-l1key=port]').value(8883);
                $('.configKey[data-l1key=ssl]').prop('checked', true);
            }
        });
    });
</script>
