jQuery(document).ready(function($) {
	$('.category-button').click(function() {
		var selectedCategory = $(this).val();
		console.log(selectedCategory);
		var getbtn = $(this);
		
		// Assuming you have a way to retrieve the user ID, replace 'userId' with the actual user ID
		
		var ajaxUrl= category_container.url;
        var nonce= category_container.nonce;
		$.ajax({
			type: 'POST',
			url: ajaxUrl,
			data: { category: selectedCategory, action: 'save_category',nonce:nonce  },
			success: function(response) {
				// $('#message').text(response);
				console.log(response);

				if(response === "limit reached"){
					alert("limit reached");
				}else{
					var btntick = getbtn.find('.ticker');
					console.log(btntick);
					btntick.toggleClass('dashicons dashicons-yes selected');
					getbtn.remove();
					var element = $('.cat_available');
        
					// Change the inner HTML
					element.html(response.creditavailable);
					var element1 = $('.cat_selected');
			
					// Change the inner HTML
					element1.html(response.creditused);
				}
				
			},
			error: function(xhr, status, error) {
				console.error(xhr.responseText);
			},
		});
	});
});
