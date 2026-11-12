/**
 * Compares the block markup the plugin writes on the server with the markup
 * the block editor writes for the same block specs.
 *
 * For each fixture spec, the server markup must parse into valid blocks, and
 * into the same blocks and attributes as the editor's markup. HTML in
 * attributes is compared with its element attributes sorted, since the
 * editor's rich text reorders them.
 *
 * The plugin keeps inline styles the editor drops: styles on paragraphs,
 * headings and list items other than the text alignment, and colored
 * `<span>`s. For specs whose HTML holds them, the server markup must be
 * valid and hold the same blocks, in the same order, as the editor's markup;
 * the block attributes are not compared.
 *
 * Environment:
 * - `WP_BASE_URL`: site URL.
 * - `WP_USERNAME`, `WP_PASSWORD`: an administrator (default `admin` / `password`).
 * - `WP_CLI`: command that runs WP-CLI on the site (default `wp`), such as
 *   `wp --path=/var/www/html` or a container wrapper.
 * - `DIVI_MIGRATION_SPECS`: the fixtures path as WP-CLI reads it (default
 *   `wp-content/plugins/divi-migration/tests/e2e/fixtures/specs.json`,
 *   relative to the WordPress root).
 * - `DIVI_MIGRATION_SKIP_BLOCKS`: comma-separated block names whose specs
 *   may be skipped because the editor does not register them. Any other
 *   skipped spec fails the test.
 */
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );

const fixtures = require( './fixtures/specs.json' );

/**
 * Splits a command line into words. Single and double quotes group words,
 * and a backslash escapes the next character. No shell runs the words.
 *
 * @param {string} line Command line.
 * @return {string[]} Words.
 */
const splitCommand = ( line ) => {
	const words = [];
	let word = '';
	let quote = '';
	let started = false;

	for ( let index = 0; index < line.length; index++ ) {
		const character = line[ index ];

		if ( character === '\\' && quote !== "'" && index + 1 < line.length ) {
			word += line[ ++index ];
			started = true;
		} else if ( quote ) {
			if ( character === quote ) {
				quote = '';
			} else {
				word += character;
			}
		} else if ( character === '"' || character === "'" ) {
			quote = character;
			started = true;
		} else if ( /\s/.test( character ) ) {
			if ( started ) {
				words.push( word );
				word = '';
				started = false;
			}
		} else {
			word += character;
			started = true;
		}
	}

	if ( started ) {
		words.push( word );
	}

	return words;
};

const serverMarkup = () => {
	const [ command, ...args ] = splitCommand( process.env.WP_CLI || 'wp' );
	const specsPath =
		process.env.DIVI_MIGRATION_SPECS ||
		'wp-content/plugins/divi-migration/tests/e2e/fixtures/specs.json';

	return JSON.parse(
		execFileSync(
			command,
			[ ...args, 'divi-migration', 'markup', `--specs=${ specsPath }` ],
			{
				encoding: 'utf8',
				maxBuffer: 64 * 1024 * 1024,
			}
		)
	);
};

test( 'server markup matches the block editor', async ( { page } ) => {
	const markup = serverMarkup();

	expect( markup ).toHaveLength( fixtures.length );

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', process.env.WP_USERNAME || 'admin' );
	await page.fill( '#user_pass', process.env.WP_PASSWORD || 'password' );
	await page.click( '#wp-submit' );
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction(
		() => window.wp?.blocks?.getBlockTypes().length > 0
	);
	await page.addScriptTag( {
		path: path.join( __dirname, 'reference.js' ),
	} );

	const allowed = ( process.env.DIVI_MIGRATION_SKIP_BLOCKS || '' )
		.split( ',' )
		.map( ( name ) => name.trim() )
		.filter( Boolean );

	const result = await page.evaluate(
		( { specs, server } ) => {
			const { getBlockType, parse } = window.wp.blocks;

			const keepsStyles = ( spec ) => {
				if ( typeof spec.raw !== 'string' ) {
					return ( spec.innerBlocks || [] ).some( keepsStyles );
				}

				const doc = document.implementation.createHTMLDocument( '' );
				doc.body.innerHTML = spec.raw;

				const styled = [
					...doc.body.querySelectorAll( 'p,h1,h2,h3,h4,h5,h6,li' ),
				].some( ( element ) =>
					( element.getAttribute( 'style' ) || '' )
						.split( ';' )
						.some(
							( declaration ) =>
								declaration.includes( ':' ) &&
								declaration.split( ':' )[ 0 ].trim().toLowerCase() !==
									'text-align'
						)
				);

				return (
					styled ||
					[ ...doc.body.querySelectorAll( 'span[style]' ) ].some(
						( span ) => /(^|;)\s*(background-)?color\s*:/i.test(
							span.getAttribute( 'style' )
						)
					)
				);
			};

			const isRegistered = ( spec ) =>
				typeof spec.raw === 'string' ||
				( !! getBlockType( spec.name ) &&
					( spec.innerBlocks || [] ).every( isRegistered ) );

			const unregistered = ( spec ) =>
				typeof spec.raw === 'string'
					? []
					: [
							...( getBlockType( spec.name ) ? [] : [ spec.name ] ),
							...( spec.innerBlocks || [] ).flatMap( unregistered ),
					  ];

			const canonical = ( html ) => {
				const doc = document.implementation.createHTMLDocument( '' );
				doc.body.innerHTML = html;
				doc.body.querySelectorAll( '*' ).forEach( ( element ) => {
					const attributes = [ ...element.attributes ]
						.map( ( { name, value } ) => [ name, value ] )
						.sort();
					attributes.forEach( ( [ name ] ) =>
						element.removeAttribute( name )
					);
					attributes.forEach( ( [ name, value ] ) =>
						element.setAttribute( name, value )
					);
				} );
				return doc.body.innerHTML;
			};

			const normalize = ( value ) =>
				JSON.stringify( value, ( key, item ) => {
					const text = item?.toHTMLString ? item.toHTMLString() : item;
					return typeof text === 'string' && text.includes( '<' )
						? canonical( text )
						: text;
				} );

			const flatten = ( blocks, list = [] ) => {
				blocks.forEach( ( block ) => {
					list.push(
						normalize( { name: block.name, attributes: block.attributes } )
					);
					flatten( block.innerBlocks, list );
				} );
				return list;
			};

			const names = ( blocks, list = [] ) => {
				blocks.forEach( ( block ) => {
					list.push( block.name );
					names( block.innerBlocks, list );
				} );
				return list;
			};

			const invalid = ( blocks ) =>
				blocks.flatMap( ( block ) => [
					...( block.isValid ? [] : [ block.name ] ),
					...invalid( block.innerBlocks ),
				] );

			const failures = [];
			const skipped = [];
			let compared = 0;

			specs.forEach( ( spec, index ) => {
				if ( ! isRegistered( spec ) ) {
					skipped.push( ...unregistered( spec ) );
					return;
				}

				compared++;

				const actual = parse( server[ index ] );
				const expected = parse( window.diviMigrationReference( [ spec ] ) );
				const invalidBlocks = invalid( actual );
				const styled = keepsStyles( spec );
				const actualList = styled ? names( actual ) : flatten( actual );
				const expectedList = styled
					? names( expected )
					: flatten( expected );
				const position = expectedList.findIndex(
					( item, at ) => item !== actualList[ at ]
				);

				if (
					invalidBlocks.length ||
					position !== -1 ||
					actualList.length !== expectedList.length
				) {
					failures.push( {
						index,
						spec: spec.name || 'raw',
						invalid: invalidBlocks,
						expected: expectedList[ position ]?.slice( 0, 500 ),
						actual: actualList[ position ]?.slice( 0, 500 ),
					} );
				}
			} );

			return { compared, failures, skipped };
		},
		{ specs: fixtures, server: markup }
	);

	const unexpected = [ ...new Set( result.skipped ) ].filter(
		( name ) => ! allowed.includes( name )
	);

	expect( result.compared ).toBeGreaterThan( 0 );
	expect( unexpected, 'Skipped specs for unregistered blocks' ).toEqual( [] );
	expect( result.failures ).toEqual( [] );
} );
