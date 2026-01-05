$(document).ready(function() {
    // Function to load hotspot servers and bandwidth profiles
    function loadInitialData() {
        // Placeholder for API call to get hotspot servers
        $.ajax({
            url: 'api/get_hotspot_servers.php', // This API endpoint needs to be created
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                var serverSelect = $('#hotspot-server');
                serverSelect.empty();
                if(data.servers && data.servers.length > 0) {
                    $.each(data.servers, function(index, server) {
                        serverSelect.append($('<option>', {
                            value: server.id,
                            text: server.name
                        }));
                    });
                } else {
                    serverSelect.append($('<option>', {
                        value: '',
                        text: 'No servers found'
                    }));
                }
            },
            error: function() {
                $('#hotspot-server').append($('<option>', {
                    value: '',
                    text: 'Error loading servers'
                }));
            }
        });

        // Placeholder for API call to get bandwidth limit groups
        $.ajax({
            url: 'api/get_bandwidth_profiles.php', // This API endpoint needs to be created
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                var bandwidthSelect = $('#bandwidth-limit');
                bandwidthSelect.empty();
                if(data.profiles && data.profiles.length > 0) {
                    $.each(data.profiles, function(index, profile) {
                        bandwidthSelect.append($('<option>', {
                            value: profile.name,
                            text: profile.name
                        }));
                    });
                } else {
                    bandwidthSelect.append($('<option>', {
                        value: '',
                        text: 'No profiles found'
                    }));
                }
            },
            error: function() {
                $('#bandwidth-limit').append($('<option>', {
                    value: '',
                    text: 'Error loading profiles'
                }));
            }
        });
    }

    // Handle form submission
    $('#form-generate-vouchers').on('submit', function(e) {
        e.preventDefault();

        var formData = {
            'hotspot-server': $('#hotspot-server').val(),
            'chars': $('input[name="chars"]:checked').map(function() { return this.value; }).get(),
            'login-length': $('#login-length').val(),
            'password-length': $('#password-length').val(),
            'bandwidth-limit': $('#bandwidth-limit').val(),
            'time-limit': $('#time-limit').val(),
            'time-unit': $('select[name="time-unit"]').val(),
            'user-count': $('#user-count').val(),
            'price': $('#price').val(),
            'validity': $('#validity').val()
        };

        // Placeholder for API call to generate vouchers
        $.ajax({
            url: 'api/generate_vouchers_direct.php', // This API endpoint needs to be created
            type: 'POST',
            data: JSON.stringify(formData),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if(response.status === 'success') {
                    alert('Vouchers generated successfully!');
                    // Optionally, display the generated vouchers
                } else {
                    alert('Error generating vouchers: ' + response.message);
                }
            },
            error: function() {
                alert('An unexpected error occurred.');
            }
        });
    });

    // Load initial data when the page loads
    loadInitialData();
});
