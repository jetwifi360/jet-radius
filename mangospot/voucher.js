function Select() {
    $.ajax({
        url: "./api/index.php?pages=voucher",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: "profiles",
        success: function (response) {
            if(response.status) {
                $('select.profiles').empty(); // Clear existing options
                $.each(response.data, function (i, params) {
                    $('select.profiles').append('<option value="' + params.groupname + '">' + params.groupname + '</option>');
                });
            }
        }
    });
    $.ajax({
        url: "./api/index.php?pages=voucher",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: "theme",
        success: function (themes) {
            $('select#theme').empty();
            var items = (themes && themes.data && Array.isArray(themes.data)) ? themes.data : (Array.isArray(themes) ? themes : []);
            $.each(items, function (e, theme) {
                var type = theme.type || 'html';
                var label = (type === 'designer' ? 'Designer: ' + theme.name : theme.name);
                var value = (type === 'designer' ? 'designer_' + theme.id : 'theme_' + theme.id);
                $('select#theme').append('<option value="' + value + '">' + label + '</option>');
            });
        },
        error: function() {
            $('select#theme').empty();
        }
    });
};

function Tables() {
    var Table = $('#tables').DataTable({
        "responsive": true,
        "processing": true,
        "serverSide": true,
        "ajax": {
            url: "./api/index.php?pages=voucher&data",
            headers: {
                "Api": $.cookie("BSK_API"),
                "Key": $.cookie("BSK_KEY"),
                "Accept": "application/json"
            },
            method: "POST"
        },
        "columns": [{
                "data": "id",
                "orderable": false,
                "className": 'text-center',
                render: function (data, type, row) {
                    return '<input type="checkbox" name="remove[]" value="' + row.id + '">';
                }
            }, {
                "data": "batch_name",
                render: function (data, type, row) {
                    return '<a href="#" class="text-info view-details" data-id="' + row.id + '">' + row.batch_name + '</a>';
                }
            },
            {
                "data": "profile"
            },
            {
                "data": "unit_price"
            },
            {
                "data": "quantity"
            },
            {
                "data": "created_at"
            },
            {
                "data": "id",
                "className": 'dt-body-right',
                render: function (data, type, row) {
                    return '<a class="btn btn-warning btn-sm" href="#" data-toggle="modal" data-target="#print" onclick="$(\'#data\').val(\'' + row.id + '\'); Prints(\'' + row.id + '\');"><i class="fa fa-print"></i></a>';
                }
            }
        ],
        "order": [
            [5, 'desc']
        ],
        "iDisplayLength": 10
    });
    
    // Details Click
    $('body').off('click', '.view-details').on('click', '.view-details', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        $.ajax({
            url: "./api/index.php?pages=voucher",
            headers: {"Api": $.cookie("BSK_API"), "Key": $.cookie("BSK_KEY")},
            method: "GET",
            dataType: "JSON",
            data: {detail: id},
            success: function(res){
                var tbody = $('#details-table tbody');
                tbody.empty();
                if(res.status && res.data){
                    $.each(res.data, function(i, row){
                        tbody.append('<tr><td>'+row.username+'</td><td>'+row.password+'</td><td>'+row.serial_number+'</td></tr>');
                    });
                    $('#details-modal').modal('show');
                } else {
                    swal("Info", "No cards found for this batch.", "info");
                }
            },
            error: function() {
                swal("Error", "Failed to load details.", "error");
            }
        });
    });
};

function Prints(data, theme) {
    // Check if this is a designer template (starts with "designer_")
    if(theme && theme.startsWith('designer_')) {
        var templateId = theme.substring(9); // Remove "designer_" prefix
        loadDesignerTemplateForPrint(data, templateId);
        return;
    }
    
    // Handle regular themes (starts with "theme_" or legacy themes)
    var themeId = theme;
    if(theme && theme.startsWith('theme_')) {
        themeId = theme.substring(6); // Remove "theme_" prefix
    }
    
    $.ajax({
        url: "./api/index.php?pages=voucher",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: {
            "print": data,
            "themes": themeId
        },
        beforeSend: function () {
            $('#print-content').empty().html('<div class="text-center"><img src="./dist/img/loader.gif"></div>');
        },
        success: function (prints) {
            var theme = '';
            if(prints.status && prints.print) {
                $.each(prints.print, function (e, params) {
                    theme += params;
                });
                $('#print-content').html(theme).find('.qr-code').each(function (i, val) {
                    $(this).qrcode({
                        render: "image",
                        size: 75,
                        text: $(this).data('code')
                    });
                });
            } else {
                $('#print-content').html('<div class="alert alert-danger">Failed to load print data</div>');
            }
        }
    });
}

function Action() {
    // Generate Batch
    $('#form-batch').off('submit').on('submit', function(e){
        e.preventDefault();
        var form = $(this);
        var btn = form.find('button[type="submit"]');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Generating...');
        
        $.ajax({
            url: "./api/voucher",
            headers: {"Api": $.cookie("BSK_API"), "Key": $.cookie("BSK_KEY")},
            method: "POST",
            dataType: "JSON",
            data: form.serialize(),
            success: function(res){
                btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Generate');
                if(res.status){
                    swal("Success", res.data, "success");
                    $('#add-batch').modal('hide');
                    $('#tables').DataTable().ajax.reload();
                } else {
                    swal("Error", res.data || "Unknown error", "error");
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Generate');
                swal("Error", "Request failed: " + error, "error");
            }
        });
    });

    // Import Batch
    $('#form-import').off('submit').on('submit', function(e){
        e.preventDefault();
        var form = $(this);
        var btn = form.find('button[type="submit"]');
        var formData = new FormData(this);
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Importing...');
        
        $.ajax({
            url: "./api/voucher",
            headers: {"Api": $.cookie("BSK_API"), "Key": $.cookie("BSK_KEY")},
            method: "POST",
            dataType: "JSON",
            data: formData,
            contentType: false,
            processData: false,
            success: function(res){
                btn.prop('disabled', false).html('<i class="fa fa-upload"></i> Import');
                if(res.status){
                    swal("Success", res.data, "success");
                    $('#import-batch').modal('hide');
                    $('#tables').DataTable().ajax.reload();
                    form[0].reset();
                } else {
                    swal("Error", res.data || "Unknown error", "error");
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="fa fa-upload"></i> Import');
                swal("Error", "Import failed: " + error, "error");
            }
        });
    });

    // Bulk Actions
    $('.action-btn').off('click').on('click', function(e){
        e.preventDefault();
        var action = $(this).data('action');
        var ids = [];
        $('input[name="remove[]"]:checked').each(function(){
            ids.push($(this).val());
        });
        
        if(ids.length === 0){
            swal("Warning", "Please select at least one batch", "warning");
            return;
        }
        
        if(action === 'print'){
            $('#data').val(ids.join(','));
            $('#print').modal('show');
            Prints(ids.join(','), $('#theme').val());
            return;
        }
        
        if(action === 'export'){
            var btn = $('.action-btn[data-action="export"]');
            var originalText = btn.html();
            btn.html('<i class="fa fa-spinner fa-spin"></i> Exporting...');
            
            // Use AJAX with Blob to support Headers
            var xhr = new XMLHttpRequest();
            xhr.open('POST', './api/voucher', true);
            xhr.responseType = 'blob';
            xhr.setRequestHeader("Api", $.cookie("BSK_API"));
            xhr.setRequestHeader("Key", $.cookie("BSK_KEY"));
            xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
            
            xhr.onload = function () {
                btn.html(originalText);
                if (this.status === 200) {
                    var blob = new Blob([this.response], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                    var link = document.createElement('a');
                    link.href = window.URL.createObjectURL(blob);
                    link.download = "vouchers_export.xlsx";
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    swal("Error", "Export failed.", "error");
                }
            };
            
            xhr.onerror = function() {
                 btn.html(originalText);
                 swal("Error", "Network error during export.", "error");
            };
            
            xhr.send('export_batch=1&ids=' + encodeURIComponent(ids.join(',')));
            return;
        }
        
        swal({
            title: "Are you sure?",
            text: "Perform " + action + " on selected batches?",
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "Yes, do it!",
            closeOnConfirm: false
        }, function(){
            var data = {action: action, ids: ids};
            if(action === 'delete') data = {delete_batch: 1, ids: ids};
            
            $.ajax({
                url: "./api/voucher",
                headers: {"Api": $.cookie("BSK_API"), "Key": $.cookie("BSK_KEY")},
                method: "POST",
                dataType: "JSON",
                data: data,
                success: function(res){
                    swal("Success", res.data, "success");
                    $('#tables').DataTable().ajax.reload();
                    $('#CheckAll').prop('checked', false);
                },
                error: function(xhr, status, error) {
                    swal("Error", "Action failed: " + error, "error");
                }
            });
        });
    });

    // CheckAll
    $('#CheckAll').click(function (e) {
        var table = $(e.target).closest('table');
        $('td input:checkbox', table).prop('checked', this.checked);
    });
    
    // Theme Change
    $('select#theme').change(function () {
        Prints($('#data').val(), $(this).val());
    });
    
    // Print Button
    $('body').off('click', '.print').on('click', '.print', function () {
        var divToPrint = document.getElementById('print-content');
        var newWin = window.open('', 'Print-Window');
        newWin.document.open();
        newWin.document.write('<html><style>table{border-collapse: collapse; font-size: x-small; width: 100%;} .table td, .table th{border: 1px solid black;padding: 0 5px;} .text-uppercase{text-transform: uppercase;}</style><body onload="window.print()">' + divToPrint.innerHTML + '</body></html>');
        newWin.document.close();
        setTimeout(function () {
            newWin.close();
        }, 10);
    });
};

function loadDesignerTemplates() {}

function loadDesignerTemplateForPrint(batchData, templateId) {
    // Use the same API endpoint as regular themes, but with designer template ID
    $.ajax({
        url: "./api/voucher",
        headers: {
            "Api": $.cookie("BSK_API"),
            "Key": $.cookie("BSK_KEY"),
            "Accept": "application/json"
        },
        method: "GET",
        dataType: "JSON",
        data: {
            "print": batchData,
            "themes": templateId
        },
        beforeSend: function () {
            $('#print-content').empty().html('<div class="text-center"><img src="./dist/img/loader.gif"></div>');
        },
        success: function (response) {
            if(response.status && response.print) {
                var theme = '';
                $.each(response.print, function (e, params) {
                    theme += params;
                });
                $('#print-content').html(theme).find('.qr-code').each(function (i, val) {
                    $(this).qrcode({
                        render: "image",
                        size: 75,
                        text: $(this).data('code')
                    });
                });
            } else {
                $('#print-content').html('<div class="alert alert-danger">Failed to load designer template data</div>');
            }
        },
        error: function() {
            $('#print-content').html('<div class="alert alert-danger">Failed to load designer template</div>');
        }
    });
}

function renderDesignerTemplate(template, batchData) {
    // This is a placeholder - the actual rendering would need to be implemented
    // based on how the designer templates are structured
    var html = '<div class="designer-template">';
    html += '<h3>Designer Template: ' + (template.name || 'Untitled') + '</h3>';
    html += '<p>Batch data loaded successfully. Template rendering would go here.</p>';
    html += '<p>Template dimensions: ' + (template.width || 600) + 'x' + (template.height || 350) + '</p>';
    html += '</div>';
    
    $('#print-content').html(html);
}

(function () {
    'use strict';
    Select();
    Tables();
    Action();
})();