/* This file is part of Jeedom - plugin Victron GX */

$('#table_cmd').sortable({
    axis: 'y',
    cursor: 'move',
    items: '.cmd',
    placeholder: 'ui-state-highlight',
    tolerance: 'intersect',
    forcePlaceholderSize: true
});

$('#bt_victronSync').off('click').on('click', function () {
    $.ajax({
        type: 'POST',
        url: 'plugins/victron/core/ajax/victron.ajax.php',
        data: {action: 'sync'},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({message: data.result, level: 'danger'});
                return;
            }
            $.fn.showAlert({message: '{{Synchronisation demandée, rechargez la page dans quelques secondes}}', level: 'success'});
        }
    });
});

$('#bt_victronSyncEq').off('click').on('click', function () {
    var id = $('.eqLogicAttr[data-l1key=id]').value();
    $.ajax({
        type: 'POST',
        url: 'plugins/victron/core/ajax/victron.ajax.php',
        data: {action: 'syncEq', id: id},
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            if (data.state != 'ok') {
                $.fn.showAlert({message: data.result, level: 'danger'});
                return;
            }
            $.fn.showAlert({message: '{{Commandes manquantes recréées}}', level: 'success'});
            setTimeout(function () {
                $('.eqLogicDisplayCard[data-eqLogic_id=' + id + ']').click();
            }, 3000);
        }
    });
});

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">';
    tr += '<span class="cmdAttr" data-l1key="type" style="display:none;"></span>';
    tr += '<span class="cmdAttr" data-l1key="subType" style="display:none;"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="cmdAttr label label-default" data-l1key="logicalId"></span><br>';
    tr += '<small><span class="cmdAttr" data-l1key="configuration" data-l2key="kind"></span> / <span class="cmdAttr" data-l1key="configuration" data-l2key="metric_type"></span></small>';
    tr += '</td>';
    tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>';
    tr += '<td>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    if (init(_cmd.type) == 'info') {
        tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> ';
        tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> ';
    }
    if (init(_cmd.subType) == 'numeric' || init(_cmd.subType) == 'slider') {
        tr += '<div style="margin-top:5px;">';
        tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;display:inline-block;"> ';
        tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;display:inline-block;"> ';
        tr += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" title="{{Unité}}" style="width:30%;display:inline-block;">';
        tr += '</div>';
    }
    tr += '</td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += ' <i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer}}"></i>';
    tr += '</td>';
    tr += '</tr>';
    $('#table_cmd tbody').append(tr);
    var $tr = $('#table_cmd tbody tr').last();
    $tr.setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType($tr, init(_cmd.subType));
}
