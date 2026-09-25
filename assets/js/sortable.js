// sortable.js - drag-and-drop reordering for a list of elements.
// Uses pointer events, so it works with a mouse and on touch screens.
//
//   makeSortable(container, { item: '.row', handle: '.drag-handle', onEnd: (movedEl) => {...} })
//
// Only direct children of `container` matching `item` are reordered; drag starts from the `handle`.
function makeSortable(container, { item, handle, onEnd }) {
	const EDGE = 60; // px from the window edge where the page starts auto-scrolling

	container.addEventListener('pointerdown', (e) => {
		const grip = e.target.closest(handle);
		if (!grip || !container.contains(grip) || e.button > 0) return;
		const dragged = grip.closest(item);
		if (!dragged || dragged.parentElement !== container) return;

		e.preventDefault();
		grip.setPointerCapture(e.pointerId);
		dragged.classList.add('dragging');
		document.body.classList.add('is-sorting'); // grabbing cursor + no text selection while dragging
		const startIndex = [...container.children].indexOf(dragged);
		let lastY = e.clientY;
		let scrollTimer = null;

		// put the dragged row before the first row whose midpoint is below the pointer
		const reposition = () => {
			const others = [...container.querySelectorAll(':scope > ' + item)].filter((el) => el !== dragged);
			const before = others.find((el) => {
				const r = el.getBoundingClientRect();
				return lastY < r.top + r.height / 2;
			});
			if (before) {
				if (dragged.nextElementSibling !== before) container.insertBefore(dragged, before);
			} else if (others.length) {
				others[others.length - 1].after(dragged);
			}
		};

		// keep scrolling while the pointer sits near the top/bottom of the window
		const autoScroll = () => {
			const dy = lastY < EDGE ? -12 : lastY > window.innerHeight - EDGE ? 12 : 0;
			if (dy) { window.scrollBy(0, dy); reposition(); }
		};

		const move = (ev) => { lastY = ev.clientY; reposition(); };
		const end = () => {
			grip.removeEventListener('pointermove', move);
			grip.removeEventListener('pointerup', end);
			grip.removeEventListener('pointercancel', end);
			clearInterval(scrollTimer);
			dragged.classList.remove('dragging');
			document.body.classList.remove('is-sorting');
			if ([...container.children].indexOf(dragged) !== startIndex && onEnd) onEnd(dragged);
		};

		grip.addEventListener('pointermove', move);
		grip.addEventListener('pointerup', end);
		grip.addEventListener('pointercancel', end);
		scrollTimer = setInterval(autoScroll, 30);
	});
}
