(function ($) {
	$(".cc-num-valid").hide();
	$(".cc-num-invalid").hide();
	$("input.cc-num").payment('formatCardNumber');
	$('input.cc-exp').payment('formatCardExpiry');
	$('input.cc-cvc').payment('formatCardCVC');	
}(jQuery));