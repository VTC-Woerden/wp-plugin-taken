/*
 * JavaScript for the Wedstrijden admin page:
 * 1. Counts how many tasks every team has, and updates the numbers while you choose teams.
 * 2. Warns you when you leave the page without saving.
 */

const vtcTakenForm = document.querySelector('.vtc-taken-matches-form');

if (vtcTakenForm) {
	let hasUnsavedChanges = false;

	function vtcTakenUpdateCounts() {
		// Start every team at 0
		const counts = {};
		document.querySelectorAll('.vtc-taken-counts tr').forEach(function (row) {
			counts[row.dataset.team] = 0;
		});

		// Add 1 for every select where this team is chosen
		document.querySelectorAll('.vtc-taken-team-select').forEach(function (select) {
			if (select.value !== '0' && counts[select.value] !== undefined) {
				counts[select.value]++;
			}
		});

		// Show the numbers
		const rows = Array.from(document.querySelectorAll('.vtc-taken-counts tr'));
		rows.forEach(function (row) {
			row.querySelector('.vtc-taken-count').textContent = counts[row.dataset.team];
		});

		// Sort the overview: most tasks at the top, the same number sorted by team name
		rows.sort(function (a, b) {
			const difference = counts[b.dataset.team] - counts[a.dataset.team];
			if (difference !== 0) {
				return difference;
			}
			return a.cells[0].textContent.localeCompare(b.cells[0].textContent, 'nl', { numeric: true });
		});

		// appendChild moves an existing row, so this puts the rows in the new order
		const tbody = document.querySelector('.vtc-taken-counts tbody');
		if (tbody) {
			rows.forEach(function (row) {
				tbody.appendChild(row);
			});
		}

		vtcTakenUpdateDayCounts();
		vtcTakenCheckConflicts();
	}

	// 120 -> '2 uur', 90 -> '1,5 uur', 45 -> '45 minuten'
	function vtcTakenFormatMinutes(minutes) {
		if (minutes % 30 === 0 && minutes >= 60) {
			return String(minutes / 60).replace('.', ',') + ' uur';
		}
		return minutes + ' minuten';
	}

	/*
	 * The checker. For every chosen team:
	 * - RED (error): the team's own match is too close to the task.
	 *   "Too close" = less than vtcTakenCheckData.matchMinutes apart (set on the Instellingen page).
	 *   Example with 120 minutes: the team plays at 13:00, so a task at 11:00 or 15:00 is fine, 14:30 is not.
	 * - ORANGE (warning): the team plays an away match this day (they have to travel),
	 *   or the team doesn't play at all on this day (they have to come to the hall extra).
	 * Teams that are not linked to Nevobo can't be checked, they stay normal.
	 * Having more than one task at the same time is allowed, that is not a problem.
	 *
	 * vtcTakenCheckData is printed by vtc_taken_page_matches() in admin-matches.php.
	 */
	function vtcTakenCheckConflicts() {
		const data = window.vtcTakenCheckData || { teams: {}, matchMinutes: 120, days: {} };
		const selects = document.querySelectorAll('.vtc-taken-team-select');
		let problemCount = 0;
		let warningCount = 0;

		// Remove the old messages first
		document.querySelectorAll('.vtc-taken-conflict-message').forEach(function (message) {
			message.remove();
		});

		selects.forEach(function (select) {
			select.classList.remove('vtc-taken-conflict', 'vtc-taken-warning');

			if (select.value === '0') {
				return;
			}

			const nevoboName = (data.teams[select.value] || '').toLowerCase();

			// Not linked to Nevobo: we don't know when this team plays
			if (nevoboName === '') {
				return;
			}

			const row = select.closest('tr');
			const day = row.dataset.day;
			const start = parseInt(row.dataset.start, 10);
			const errors = [];
			const awayWarnings = [];
			let playsThisDay = false;

			(data.days[day] || []).forEach(function (match) {
				const playsInMatch = match.home.toLowerCase() === nevoboName || match.away.toLowerCase() === nevoboName;

				if (!playsInMatch) {
					return;
				}

				playsThisDay = true;

				// An away match on this day: a warning, the team has to travel
				if (match.isAway) {
					awayWarnings.push(
						'Let op: speelt deze dag een uitwedstrijd om ' + match.time + ' (' + match.label +
						(match.location ? ', ' + match.location : '') + ').'
					);
					return;
				}

				// Is the team's own home match too close to this task?
				if (Math.abs(match.start - start) < data.matchMinutes) {
					errors.push(
						'Speelt zelf om ' + match.time + ' (' + match.label + '). ' +
						'Er moet minimaal ' + vtcTakenFormatMinutes(data.matchMinutes) + ' tussen wedstrijd en taak zitten.'
					);
				}
			});

			// Red goes first, then the away warning, then "doesn't play this day"
			if (errors.length > 0) {
				problemCount++;
				vtcTakenShowMessage(select, 'vtc-taken-conflict', errors.join(' / '));
			} else if (awayWarnings.length > 0) {
				warningCount++;
				vtcTakenShowMessage(select, 'vtc-taken-warning', awayWarnings.join(' / '));
			} else if (!playsThisDay) {
				warningCount++;
				vtcTakenShowMessage(select, 'vtc-taken-warning', 'Let op: dit team speelt deze dag niet.');
			}
		});

		// The summary in the sidebar
		const summary = document.querySelector('.vtc-taken-conflict-summary');
		if (summary) {
			const lines = [];

			if (problemCount > 0) {
				lines.push(problemCount + (problemCount === 1 ? ' taak kan niet' : ' taken kunnen niet') + ' (rood).');
			}
			if (warningCount > 0) {
				lines.push(warningCount + (warningCount === 1 ? ' taak heeft' : ' taken hebben') + ' een waarschuwing (oranje).');
			}
			if (lines.length === 0) {
				lines.push('Geen problemen gevonden.');
			}

			summary.textContent = lines.join(' ');
			summary.classList.toggle('vtc-taken-conflict-summary--problems', problemCount > 0);
			summary.classList.toggle('vtc-taken-conflict-summary--warnings', problemCount === 0 && warningCount > 0);
		}
	}

	// Colours the select (red or orange) and shows the reason below it
	function vtcTakenShowMessage(select, className, text) {
		select.classList.add(className);

		const message = document.createElement('div');
		message.className = 'vtc-taken-conflict-message ' + className + '-message';
		message.textContent = text;
		select.insertAdjacentElement('afterend', message);
	}

	/*
	 * The counter at the bottom of every day: how many tasks every team has on that day.
	 * Teams with more than one task that day are highlighted.
	 */
	function vtcTakenUpdateDayCounts() {
		document.querySelectorAll('.vtc-taken-day').forEach(function (day) {
			// Count per team name, only the selects of this day
			const counts = {};

			day.querySelectorAll('.vtc-taken-team-select').forEach(function (select) {
				if (select.value === '0') {
					return;
				}

				const teamName = select.options[select.selectedIndex].text;
				counts[teamName] = (counts[teamName] || 0) + 1;
			});

			const list = day.querySelector('.vtc-taken-day-counts-list');
			list.innerHTML = '';

			const teamNames = Object.keys(counts).sort();

			if (teamNames.length === 0) {
				list.textContent = 'nog niemand ingepland';
				return;
			}

			teamNames.forEach(function (teamName) {
				const item = document.createElement('span');
				item.className = 'vtc-taken-day-count';
				if (counts[teamName] > 1) {
					item.className += ' vtc-taken-day-count--multiple';
				}

				// textContent (not innerHTML) so a team name can never break the page
				item.textContent = teamName + ': ' + counts[teamName];
				list.appendChild(item);
			});
		});
	}

	vtcTakenForm.addEventListener('change', function () {
		hasUnsavedChanges = true;
		vtcTakenUpdateCounts();
	});

	vtcTakenForm.addEventListener('submit', function () {
		hasUnsavedChanges = false;
	});

	window.addEventListener('beforeunload', function (event) {
		if (hasUnsavedChanges) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	vtcTakenUpdateCounts();
}
