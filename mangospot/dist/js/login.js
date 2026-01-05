function runData() {
	$.ajax({
		url: "./api/data",
		headers: {
			"Api": $.cookie("BSK_API"),
			"Key": $.cookie("BSK_KEY"),
			"Accept": "application/json"
		},
		method: 'GET',
		dataType: "JSON",
		data: "cover",
		success: function (result) {
			var list = '';
			$.each(result.data, function (i, val) {
				list += '<div class="fadeOut item auth-cover-img overlay-wrap" style="background-image:url(' + val.image + ');">';
				list += '<div class="auth-cover-info py-xl-0 pt-100 pb-50">';
				list += '<div class="auth-cover-content text-center w-xxl-75 w-sm-90 w-xs-100">';
				list += '<h1 class="display-3 text-white">' + val.title + '</h1>';
				list += '<p class="text-white">' + val.info + '</p>';
				list += '</div>';
				list += '</div>';
				list += '<div class="bg-overlay bg-trans-dark-50"></div>';
				list += '</div>';
			});
			$('#owl_demo_1').html(list).owlCarousel({
				items: 1,
				animateOut: 'fadeOut',
				loop: true,
				margin: 10,
				autoplay: true,
				mouseDrag: false,
				dots: false
			});
		}
	});
}

(function () {
	'use strict';
	runData();

	// Login Form Submission
	$('#form-login').on('submit', function(e) {
		e.preventDefault();
		var form = $(this);
		var btn = form.find('button[type="submit"]');
		var btnText = btn.html();

		btn.attr('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...');

		$.ajax({
			url: "./api/login",
			type: "POST",
			data: form.serialize() + "&login=true",
			dataType: "JSON",
			success: function(result) {
				if (result.status) {
					// Set cookies
					$.cookie("BSK_API", result.data.api, { expires: result.data.exp, path: '/' });
					$.cookie("BSK_KEY", result.data.key, { expires: result.data.exp, path: '/' });
					$.cookie("BSK_TOKEN", result.data.token, { expires: result.data.exp, path: '/' }); // Might need this?
					
					// Redirect
					window.location.href = "./";
				} else {
					// Show error
					alert(result.data); // Simple alert for now, could be improved
					btn.attr('disabled', false).html(btnText);
				}
			},
			error: function(xhr, status, error) {
				console.error(xhr);
				alert("Connection error: " + error);
				btn.attr('disabled', false).html(btnText);
			}
		});
	});

	// Forgot Password Form Submission
	$('#form-forgote').on('submit', function(e) {
		e.preventDefault();
		var form = $(this);
		var btn = form.find('button[type="submit"]');
		var btnText = btn.html();

		btn.attr('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...');

		$.ajax({
			url: "./api/login", // Forgot password logic is also in login.php
			type: "POST",
			data: form.serialize() + "&forgot=true",
			dataType: "JSON",
			success: function(result) {
				if (result.status) {
					alert(result.data);
					$('#add-forgote').modal('hide');
					form[0].reset();
				} else {
					alert(result.data);
				}
				btn.attr('disabled', false).html(btnText);
			},
			error: function(xhr, status, error) {
				alert("Connection error: " + error);
				btn.attr('disabled', false).html(btnText);
			}
		});
	});

})();
