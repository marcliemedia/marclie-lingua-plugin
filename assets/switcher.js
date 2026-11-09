/**
 * Marclie Lingua — language switcher dropdowns ([data-mlg-switch], src/Switcher.php).
 * Click toggles (a mouse click on a hover-opened menu keeps it open); with a mouse (hover + fine pointer) hovering
 * opens and leaving closes after 200 ms; Escape closes and returns focus to the button; a click outside or focus
 * moving out closes; one menu open at a time.
 */
( () => {
	'use strict';

	const hover = window.matchMedia( '(hover: hover) and (pointer: fine)' );
	const switches = [];

	const setOpen = ( item, open ) => {
		if ( open ) {
			switches.forEach( ( other ) => other !== item && setOpen( other, false ) );
		}
		clearTimeout( item.timer );
		item.toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		item.menu.hidden = ! open;
	};

	document.querySelectorAll( '[data-mlg-switch]' ).forEach( ( root ) => {
		const toggle = root.querySelector( '.mlg-switch__toggle[aria-controls]' );
		const menu = toggle && document.getElementById( toggle.getAttribute( 'aria-controls' ) );
		if ( ! menu ) {
			return;
		}
		const item = { root, toggle, menu, timer: 0 };
		switches.push( item );
		setOpen( item, false );

		// a mouse click (detail > 0) after the hover opened the menu keeps it open; keyboard Enter / Space toggles
		toggle.addEventListener( 'click', ( event ) => setOpen( item, ( hover.matches && event.detail > 0 ) || menu.hidden ) );
		root.addEventListener( 'mouseenter', () => hover.matches && setOpen( item, true ) );
		root.addEventListener( 'mouseleave', () => {
			if ( hover.matches ) {
				clearTimeout( item.timer );
				item.timer = setTimeout( () => setOpen( item, false ), 200 );
			}
		} );
		root.addEventListener( 'focusout', ( event ) => {
			if ( event.relatedTarget && ! root.contains( event.relatedTarget ) ) {
				setOpen( item, false );
			}
		} );
		root.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key && ! menu.hidden ) {
				event.preventDefault();
				setOpen( item, false );
				toggle.focus();
			}
		} );
	} );

	document.addEventListener( 'click', ( event ) => {
		switches.forEach( ( item ) => ! item.root.contains( event.target ) && setOpen( item, false ) );
	} );
} )();
