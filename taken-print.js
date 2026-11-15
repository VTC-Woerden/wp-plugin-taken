/*
 * The "Printen" button of the [vtc_taken] shortcode.
 *
 * Printing the whole web page gives empty pages on some themes (header, footer and other content
 * still take up space). So we copy only the tasks into a small, invisible page of its own (an iframe),
 * with only our own CSS, and print that. The theme can't get in the way.
 */

function vtcTakenPrint(block) {
	// Our stylesheet. WordPress gives it the id "vtc_taken-css" (the handle from plugin.php + "-css").
	const stylesheet = document.getElementById('vtc_taken-css');
	const cssUrl = stylesheet ? stylesheet.href : '';

	// A copy of the tasks, without the button
	const copy = block.cloneNode(true);
	copy.querySelectorAll('.vtc-taken__print-button').forEach(function (button) {
		button.remove();
	});

	// The same font as on the website (when the browser has it)
	const font = window.getComputedStyle(block).fontFamily;

	// An invisible iframe: a small page of its own
	const iframe = document.createElement('iframe');
	iframe.setAttribute('aria-hidden', 'true');
	iframe.style.position = 'fixed';
	iframe.style.right = '0';
	iframe.style.bottom = '0';
	iframe.style.width = '0';
	iframe.style.height = '0';
	iframe.style.border = '0';
	document.body.appendChild(iframe);

	// Print as soon as the small page (and our CSS) has loaded, then remove it again
	iframe.addEventListener('load', function () {
		iframe.contentWindow.focus();
		iframe.contentWindow.print();

		setTimeout(function () {
			iframe.remove();
		}, 1000);
	});

	const page = iframe.contentWindow.document;
	page.open();
	page.write(
		'<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">' +
		'<title>' + document.title.replace(/</g, '&lt;') + '</title>' +
		(cssUrl ? '<link rel="stylesheet" href="' + cssUrl + '">' : '') +
		'<style>body { margin: 0; font-family: ' + font + '; }</style>' +
		'</head><body class="vtc-taken-print-page">' + copy.outerHTML + '</body></html>'
	);
	page.close();
}

document.addEventListener('DOMContentLoaded', function () {
	// The buttons
	document.querySelectorAll('.vtc-taken__print-button').forEach(function (button) {
		button.addEventListener('click', function () {
			vtcTakenPrint(button.closest('.vtc-taken-printable'));
		});
	});

	// ?print in the URL: print right away (the old zaaltaken page did the same)
	const params = new URLSearchParams(window.location.search);
	const firstBlock = document.querySelector('.vtc-taken-printable');

	if (params.has('print') && firstBlock) {
		vtcTakenPrint(firstBlock);
	}
});
