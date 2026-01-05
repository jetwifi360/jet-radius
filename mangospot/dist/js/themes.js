function Select() {
    $('#type').empty().append('<option value="radius">Radius</option>');
    $('#type').val('radius');
    $.ajax({
        url: "./api/themes",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: "type",
        beforeSend: function () {
            $('#id').empty().append('<option value="0">-- New Themes --</option>');
        },
        success: function (response) {
            $.each(response.data, function (i, val) {
                $('#id').append('<option value="' + val.id + '">' + val.name + '</option>');
            });
        }
    });
    $.ajax({
        url: "./api/themes",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: "docs",
        success: function (service) {
            var docs = '';
            $.each(service.data, function (i, doc) {
                docs += '<tr>';
                docs += '<td>' + doc.name + '</td>';
                docs += '<td>' + doc.info + '</td>';
                docs += '</tr>';
            });
            $('#docs-list').html(docs);
        }
    });
    $.ajax({
        url: "./api/themes",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: "types",
        success: function (response) {
            var ok = (response && response.status && Array.isArray(response.data) && response.data.length);
            if(ok){
                $.each(response.data, function (i, t) { if(t.value !== 'radius'){ $('#type').append('<option value="' + t.value + '">' + t.label + '</option>'); } });
            } else {
                var defaults = [
                    {value:'forgot',label:'Forgot'},
                    {value:'register',label:'Register'},
                    {value:'verification',label:'Verification'},
                    {value:'order',label:'Order'},
                    {value:'payment',label:'Payment'},
                    {value:'delivery',label:'Delivery'}
                ];
                $.each(defaults, function(i,t){ $('#type').append('<option value="'+t.value+'">'+t.label+'</option>'); });
            }
        },
        error: function(){
            var defaults = [
                {value:'forgot',label:'Forgot'},
                {value:'register',label:'Register'},
                {value:'verification',label:'Verification'},
                {value:'order',label:'Order'},
                {value:'payment',label:'Payment'},
                {value:'delivery',label:'Delivery'}
            ];
            $.each(defaults, function(i,t){ $('#type').append('<option value="'+t.value+'">'+t.label+'</option>'); });
        }
    });
};

function Action() {
    var delay;
    var editor = CodeMirror.fromTextArea(document.getElementById("code-editor"), {
        lineNumbers: true,
        theme: 'blackboard',
        mode: 'text/html'
    });
    $('select#id').change(function () {
        $.ajax({
            url: "./api/themes",
            headers: {
                "Api": $.cookie("BSK_API"),
                "Key": $.cookie("BSK_KEY"),
                "Accept": "application/json"
            },
            method: "GET",
            dataType: "JSON",
            data: {
                "detail": $(this).val()
            },
            success: function (edit) {
                if (edit.status) {
                    $.each(edit.data, function (i, val) {
                        $('#' + i).val(val);
                    });
                    editor.setValue(edit.data.content);
                    $('button.removed').attr('disabled', false).attr('data-value', edit.data.id);
                } else {
                    editor.setValue('');
                    $('#name, #content').empty().val('');
                    $('#type').val('');
                    $('a[href="#delete"]').attr('disabled', true);
                    $('button.removed').attr('disabled', true).attr('data-value', 0);
                }
            }
        });
    });
    editor.on('change', editor => {
        clearTimeout(delay);
        $('#content').val(editor.getValue());
        delay = setTimeout(updatePreview, 300);
    });
    $('#form-themes').on('submit', function(e){
        e.preventDefault();
        var t = $('#type').val() || 'radius';
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true);
        $.ajax({
            url: "./api/themes",
            headers: {
                "Api": $.cookie("BSK_API"),
                "Key": $.cookie("BSK_KEY"),
                "Accept": "application/json"
            },
            method: "POST",
            dataType: "JSON",
            data: {
                id: $('#id').val(),
                name: $('#name').val(),
                type: t,
                content: $('#content').val()
            },
            success: function(resp){
                btn.prop('disabled', false);
                if(resp && resp.status){
                    Select();
                }
            },
            error: function(){
                btn.prop('disabled', false);
            }
        });
    });

    function updatePreview() {
        var previewFrame = document.getElementById('preview');
        var preview = previewFrame.contentDocument || previewFrame.contentWindow.document;
        preview.open();
        preview.write(editor.getValue());
        preview.close();
    }
    setTimeout(updatePreview, 300);
};
(function () {
    'use strict';
    Select();
    Action();
})();