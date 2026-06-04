var successCallback = function(data) {

    var checkout_form = $( 'form.woocommerce-checkout' );
    //checkout_form.submit();

};

var errorCallback = function(data) {
    console.log(data);
};

var tokenRequest = function() {

    // here will be a payment gateway function that process all the card data from your form,
    // maybe it will need your Publishable API key which is misha_params.publishableKey
    // and fires successCallback() on success and errorCallback on failure
    return false;

};

jQuery(function($){

    var checkout_form = $( 'form.woocommerce-checkout' );
    //checkout_form.on( 'checkout_place_order', tokenRequest );

});



document.addEventListener('DOMContentLoaded', function() {
	const parentElement = document.querySelector('.woocommerce');

    // Add event listener to the parent element
    parentElement.addEventListener('input', function(event) {
        const targetElement = event.target;
        // Check if the event target matches the card expiration input field
        if (targetElement.matches('#qp_expdate')) {
            formatCardExpiration(targetElement);
        }
    });

    // Function to format the card expiration
    const formatCardExpiration = cardExpirationInput => {
        // Get the input value and apply the desired formatting
        let input = cardExpirationInput.value.replace(/\D/g, '');
        if (input.length > 2) {
            const month = input.substring(0, 2);
            const year = input.substring(2, 4);
            cardExpirationInput.value = `${month} / ${year}`;
        } else {
            cardExpirationInput.value = input;
        }
    }
	
});










	