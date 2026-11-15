/*
 * JavaScript for the "Nieuwe taak" form:
 * 1. A Dutch calendar on the date field (dd/mm/jjjj). Uses jQuery UI Datepicker, which comes with WordPress.
 * 2. Tidies up typed times: '930', '9:30' and '9.30' all become '09:30'.
 * 3. Tidies up typed dates: '1/2/2026' becomes '01/02/2026'.
 */

jQuery(function ($) {

	// 1. Dutch calendar
	$('.vtc-taken-date').datepicker({
		dateFormat: 'dd/mm/yy', // in jQuery UI 'yy' means a year with 4 digits
		firstDay: 1,            // the week starts on monday
		showOtherMonths: true,
		selectOtherMonths: true,
		monthNames: ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
		monthNamesShort: ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'],
		dayNames: ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'],
		dayNamesShort: ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'],
		dayNamesMin: ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'],
		prevText: 'Vorige',
		nextText: 'Volgende'
	});

	// 2. Times
	$('.vtc-taken-time').on('blur', function () {
		const value = this.value.trim();

		// '930' or '0930' -> hours and minutes
		let match = value.match(/^(\d{1,2})(\d{2})$/);

		// '9:30' or '9.30'
		if (!match) {
			match = value.match(/^(\d{1,2})[:.](\d{2})$/);
		}

		if (match) {
			this.value = match[1].padStart(2, '0') + ':' + match[2];
		}
	});

	// 3. Dates
	$('.vtc-taken-date').on('blur', function () {
		const match = this.value.trim().match(/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/);

		if (match) {
			this.value = match[1].padStart(2, '0') + '/' + match[2].padStart(2, '0') + '/' + match[3];
		}
	});
});
