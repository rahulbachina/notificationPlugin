jQuery(document).ready(function($) {  
	function createPopup(message) {
        var popup = document.createElement('div');
        popup.className = 'cpen-limit-reached-popup';
        var content = document.createElement('div');
        content.className = 'popup-content';
        var text = document.createElement('p');
		text.className = 'popup-text-content';
        text.textContent = message;
		var upgradebutton = document.createElement('button');
		upgradebutton.className = 'popup-upgrade-button';
		upgradebutton.textContent = 'Choose Your Plan';
		upgradebutton.addEventListener('click', function() {
			window.location.href= window.location.origin+'/pricing/';
        });
        var closeButton = document.createElement('button');
		closeButton.className = 'popup-close-button';
        closeButton.textContent = 'X';
        closeButton.addEventListener('click', function() {
            popup.remove();
        });
        content.appendChild(text);
		content.appendChild(upgradebutton);
        content.appendChild(closeButton);
        popup.appendChild(content);
        document.body.appendChild(popup);
    }

    //subscribe button clicked event
    $('#subscribeButton').click(function() {
		var selectedCategory = $(this).attr('name');
		console.log(selectedCategory);
		var getbtn = $(this);
		
		// Assuming you have a way to retrieve the user ID, replace 'userId' with the actual user ID
		var ajaxUrl= subscribeButton.url;
        var nonce= subscribeButton.nonce;
		$.ajax({
			type: 'POST',
			url: ajaxUrl,
			data: { category: selectedCategory, action: 'save_category',nonce:nonce  },
			success: function(response) {
				console.log(response);

				if(response === "limit reached"){
					createPopup('Your subscribed limit has been reached. Please upgrade your plan.');
				}else{
					console.log(response.selcategory);
					if (response.selcategory != null) {
						var dataObject = response.selcategory;
						console.log(dataObject);
						if (Array.isArray(dataObject)) {
							dataObject.forEach(element => {
								if (element === selectedCategory) {
									console.log(selectedCategory + ' exists in the array.');
									getbtn.val('Subscribed');
								} else {
									getbtn.val('Subscribe');
								}
							});
						} else {
							var dataArray = Object.values(dataObject);
							dataArray.forEach(element => {
								if (element === selectedCategory) {
									console.log(selectedCategory + ' exists in the array.');
									getbtn.val('Subscribed');
								} else {
									getbtn.val('Subscribe');
								}
							});
						}
					} else {
						getbtn.val('Subscribe');
					}
				}
				
			},
			error: function(xhr, status, error) {
				console.error(xhr.responseText);
			},
		});
	});
    
});
