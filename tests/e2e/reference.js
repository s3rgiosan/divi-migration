/**
 * Builds the block editor's markup for block specs, to compare with the
 * markup the plugin writes on the server.
 *
 * Runs in the editor page and uses its `wp.blocks` API: blocks come from
 * `createBlock()` and raw HTML specs from `rawHandler()`, with the steps the
 * plugin adds around the raw handler (see `RawHandler.php`): serialized
 * blocks in the HTML stay as they are, classed `<div>` elements become Group
 * blocks (with their ID and inline styles) or Custom HTML blocks when they
 * have other attributes, paragraphs with inline images stay paragraphs unless
 * they hold a shortcode with its own block, a paragraph that holds only a
 * video without a `src` becomes a Custom HTML block, paragraphs and headings
 * keep their ID as anchor, the rows of several table sections of one kind
 * join, Shortcode blocks lose their HTML comments, and top-level `more` and `nextpage` comments become
 * More and Page Break blocks (a paragraph splits at the comment, other
 * comments in it are dropped, and a `noteaser` comment after `more` sets
 * `noTeaser`).
 */
( () => {
	const { cloneBlock, createBlock, getBlockType, parse, rawHandler } =
		window.wp.blocks;

	// Blocks kept from serialized block markup, which take no `apply` attributes.
	const keptBlocks = new WeakSet();

	// Block delimiter pattern, as the block parser reads delimiters.
	const BLOCK_DELIMITER =
		/<!--[ \t\f\r\n]+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)[ \t\f\r\n]+({(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->)[\s\S])*?}[ \t\f\r\n]+)?(\/)?-->/g;

	// Shortcodes with their own block.
	const SHORTCODE_BLOCKS = {
		caption: 'core/image',
		gallery: 'core/gallery',
		audio: 'core/audio',
		embed: 'core/embed',
		video: 'core/video',
	};

	const PHRASING = [ 'a', 'abbr', 'audio', 'b', 'bdi', 'bdo', 'br', 'canvas', 'code', 'data', 'del', 'dfn', 'em', 'embed', 'i', 'img', 'ins', 'kbd', 'mark', 'object', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'video', 'wbr' ];

	const TYPOGRAPHY = {
		'font-weight': 'fontWeight',
		'font-style': 'fontStyle',
		'text-transform': 'textTransform',
		'text-decoration': 'textDecoration',
		'letter-spacing': 'letterSpacing',
	};

	// Removes HTML comments, and any comment start or end left over.
	const stripComments = ( value ) => {
		let text = value;

		while ( text.includes( '<!--' ) || text.includes( '-->' ) ) {
			text = text
				.replace( /<!--[\s\S]*?-->/g, '' )
				.split( '<!--' )
				.join( '' )
				.split( '-->' )
				.join( '' );
		}

		return text;
	};

	// Splits HTML at its top-level serialized blocks, or returns null when
	// it holds no delimiter or its delimiters do not pair up.
	const blockSegments = ( html ) => {
		const segments = [];
		const stack = [];
		let offset = 0;
		let start = 0;

		for ( const match of html.matchAll( BLOCK_DELIMITER ) ) {
			const delimiter = match[ 0 ];
			const position = match.index;
			const end = position + delimiter.length;
			const name = ( match[ 2 ] || 'core/' ) + match[ 3 ];

			if ( match[ 1 ] ) {
				if ( stack[ stack.length - 1 ] !== name ) {
					return null;
				}

				stack.pop();

				if ( ! stack.length ) {
					segments.push( {
						markup: unwrapDelimiters( html.slice( start, end ) ),
					} );
					offset = end;
				}

				continue;
			}

			if ( ! stack.length ) {
				segments.push( { html: html.slice( offset, position ) } );
				start = position;
			}

			if ( match[ 5 ] ) {
				if ( ! stack.length ) {
					segments.push( { markup: delimiter } );
					offset = end;
				}

				continue;
			}

			stack.push( name );
		}

		if ( ! segments.length || stack.length ) {
			return null;
		}

		segments.push( { html: html.slice( offset ) } );

		return segments;
	};

	// Removes the paragraph tags `wpautop()` writes around block delimiters.
	const unwrapDelimiters = ( markup ) => {
		let result = '';
		let offset = 0;
		let afterStart = false;

		for ( const match of markup.matchAll( BLOCK_DELIMITER ) ) {
			let between = markup.slice( offset, match.index );

			if ( afterStart ) {
				between = between.replace( /^(\s*)<\/p>/i, '$1' );
			}

			if ( match[ 1 ] ) {
				between = between.replace( /<p>(\s*)$/i, '$1' );
			}

			result += between + match[ 0 ];
			offset = match.index + match[ 0 ].length;
			afterStart = ! match[ 1 ] && ! match[ 5 ];
		}

		return result + markup.slice( offset );
	};

	// Starts a paragraph when a paragraph end comes before any paragraph start.
	const reopenParagraph = ( html ) => {
		const end = /<\/p\s*>/i.exec( html );

		if ( ! end ) {
			return html;
		}

		const start = /<p[\s>]/i.exec( html );

		if ( start && start.index < end.index ) {
			return html;
		}

		return '<p>' + html;
	};

	// Keeps the serialized blocks and converts the HTML around them.
	const segmentBlocks = ( segments ) =>
		segments.flatMap( ( segment, index ) => {
			if ( segment.markup !== undefined ) {
				const blocks = parse( segment.markup );
				blocks.forEach( ( block ) => keptBlocks.add( block ) );
				return blocks;
			}

			let html = segment.html;

			if ( index < segments.length - 1 ) {
				html = html.replace( /(?:\s|<br\s*\/?>|<p(?:\s[^>]*)?>)+$/i, '' );
			}

			if ( index > 0 ) {
				html = reopenParagraph(
					html.replace( /^(?:\s|<\/p\s*>|<br\s*\/?>)+/i, '' )
				);
			}

			return html.trim() ? rawToBlocks( html ) : [];
		} );

	// The theme's presets, without WordPress's default ones.
	const presets = ( () => {
		const features =
			window.wp.data.select( 'core/block-editor' ).getSettings()
				.__experimentalFeatures || {};
		const origins = ( value ) =>
			Array.isArray( value )
				? value
				: [ ...( value?.theme || [] ), ...( value?.custom || [] ) ];
		const normalize = ( value ) => {
			const color = value.trim().toLowerCase();

			if ( /^#[0-9a-f]{3,4}$/.test( color ) ) {
				return (
					'#' +
					color
						.slice( 1 )
						.split( '' )
						.map( ( digit ) => digit + digit )
						.join( '' )
				);
			}

			return color.includes( '(' ) ? color.replace( /\s+/g, '' ) : color;
		};
		const colors = {};
		const fonts = {};
		const sizes = {};

		origins( features.color?.palette ).forEach( ( color ) => {
			if ( typeof color.color === 'string' ) {
				colors[ normalize( color.color ) ] = color.slug;
			}
		} );

		origins( features.typography?.fontFamilies ).forEach( ( font ) => {
			[ font.name || '', ( font.fontFamily || '' ).split( ',' )[ 0 ] ].forEach(
				( name ) => {
					const key = name.trim().replace( /^["' ]+|["' ]+$/g, '' ).toLowerCase();

					if ( key ) {
						fonts[ key ] = font.slug;
					}
				}
			);
		} );

		origins( features.typography?.fontSizes ).forEach( ( size ) => {
			if ( typeof size.size === 'string' ) {
				sizes[ size.size ] = size.slug;
			}
		} );

		return { colors, fonts, sizes, normalize };
	} )();

	// The preset attribute for a value that matches a theme preset,
	// otherwise the value in the style attribute.
	const presetAttribute = ( kind, value, attribute, path ) => {
		let slug;

		if ( kind === 'color' ) {
			const color = presets.normalize( value );

			if (
				color === 'transparent' ||
				/^rgba\([\d.]+,[\d.]+,[\d.]+,0(\.0+)?\)$/.test( color )
			) {
				return {};
			}

			slug = presets.colors[ color ];
		} else if ( kind === 'font-size' ) {
			slug = presets.sizes[ value.trim() ];
		} else {
			const family = value.split( ',' )[ 0 ].trim().replace( /^["' ]+|["' ]+$/g, '' );
			slug = presets.fonts[ family.trim().toLowerCase() ];
		}

		return slug
			? { [ attribute ]: slug }
			: { style: { [ path[ 0 ] ]: { [ path[ 1 ] ]: value } } };
	};

	// Block attributes for an element's inline styles, as
	// `RawHandler::style_attributes()` reads them without the text alignment.
	const styleAttributes = ( styles ) => {
		let attributes = {};

		( styles || '' ).split( ';' ).forEach( ( declaration ) => {
			const separator = declaration.indexOf( ':' );

			if ( separator === -1 ) {
				return;
			}

			const property = declaration.slice( 0, separator ).trim().toLowerCase();
			const value = declaration
				.slice( separator + 1 )
				.replace( /\s*!important\s*$/i, '' )
				.trim();

			if ( ! property || ! value ) {
				return;
			}

			const box = () => {
				const parts = value.split( /\s+/ );
				return {
					top: parts[ 0 ],
					right: parts[ 1 ] ?? parts[ 0 ],
					bottom: parts[ 2 ] ?? parts[ 0 ],
					left: parts[ 3 ] ?? parts[ 1 ] ?? parts[ 0 ],
				};
			};
			const side = /^(margin|padding)-(top|right|bottom|left)$/.exec( property );
			let add = null;

			switch ( property ) {
				case 'color':
					add = presetAttribute( 'color', value, 'textColor', [ 'color', 'text' ] );
					break;
				case 'background-color':
					add = presetAttribute( 'color', value, 'backgroundColor', [ 'color', 'background' ] );
					break;
				case 'font-size':
					add = presetAttribute( 'font-size', value, 'fontSize', [ 'typography', 'fontSize' ] );
					break;
				case 'font-family':
					add = presetAttribute( 'font-family', value, 'fontFamily', [ 'typography', 'fontFamily' ] );
					break;
				case 'line-height':
					add = { style: { typography: { lineHeight: value.replace( /^(\d*\.?\d+)em$/, '$1' ) } } };
					break;
				case 'margin':
				case 'padding':
					add = { style: { spacing: { [ property ]: box() } } };
					break;
				default:
					if ( TYPOGRAPHY[ property ] ) {
						add = { style: { typography: { [ TYPOGRAPHY[ property ] ]: value } } };
					} else if ( side ) {
						add = { style: { spacing: { [ side[ 1 ] ]: { [ side[ 2 ] ]: value } } } };
					}
			}

			attributes = mergeAttributes( attributes, add || {} );
		} );

		return attributes;
	};

	// Group block for a classed `<div>`, or a Custom HTML block when the
	// `<div>` has attributes other than its class, ID and style.
	const groupBlock = ( node ) => {
		const other = Array.from( node.attributes ).some(
			( { name } ) => ! [ 'class', 'id', 'style' ].includes( name )
		);

		if ( other ) {
			return parse(
				`<!-- wp:html -->\n${ node.outerHTML }\n<!-- /wp:html -->`
			);
		}

		return [
			createBlock(
				'core/group',
				mergeAttributes(
					{
						className: node.className,
						...( node.id ? { anchor: node.id } : {} ),
						layout: { type: 'default' },
					},
					styleAttributes( node.getAttribute( 'style' ) )
				),
				rawToBlocks( node.innerHTML )
			),
		];
	};

	// Whether an element holds a shortcode that has its own block.
	const hasBlockShortcode = ( element ) =>
		Object.entries( SHORTCODE_BLOCKS ).some(
			( [ tag, block ] ) =>
				getBlockType( block ) &&
				new RegExp( `\\[(\\[?)(${ tag })(?![\\w-])` ).test( element.innerHTML )
		);

	// The video without a `src` that a paragraph holds on its own, or null.
	const sourceVideo = ( node ) => {
		if ( node.nodeType !== 1 ) {
			return null;
		}

		if ( node.tagName === 'VIDEO' ) {
			const alone = [ 'previousSibling', 'nextSibling' ].every( ( direction ) => {
				let sibling = node[ direction ];

				while ( sibling && ( sibling.nodeType === 8 || ( sibling.nodeType === 3 && ! sibling.textContent.trim() ) ) ) {
					sibling = sibling[ direction ];
				}

				return ! sibling || ( sibling.nodeType === 1 && ! PHRASING.includes( sibling.tagName.toLowerCase() ) );
			} );

			return alone && ! node.getAttribute( 'src' ) ? node : null;
		}

		const video = node.firstElementChild;

		return node.tagName === 'P' &&
			node.children.length === 1 &&
			video.tagName === 'VIDEO' &&
			! node.textContent.trim() &&
			! video.getAttribute( 'src' )
			? video
			: null;
	};

	// Moves the rows of each later table section into the first section of
	// its kind, so the rows of several bodies all reach the Table block.
	const mergeTableSections = ( html ) => {
		if ( ! /<table[\s>]/i.test( html ) ) {
			return html;
		}

		const holder = document.createElement( 'div' );
		holder.innerHTML = html;

		holder.querySelectorAll( 'table' ).forEach( ( table ) => {
			const first = {};

			Array.from( table.children ).forEach( ( section ) => {
				const name = section.nodeName;

				if ( ! [ 'THEAD', 'TBODY', 'TFOOT' ].includes( name ) ) {
					return;
				}

				if ( ! first[ name ] ) {
					first[ name ] = section;
					return;
				}

				first[ name ].append( ...section.childNodes );
				section.remove();
			} );
		} );

		return holder.innerHTML;
	};

	// Reads the ID of a paragraph or heading as its anchor while the raw
	// handler runs.
	const withAnchors = ( callback ) => {
		const { addFilter, removeFilter } = window.wp.hooks;
		const namespace = 'divi-migration/reference-anchor';

		addFilter(
			'blocks.getBlockAttributes',
			namespace,
			( attributes, blockType, innerHTML ) => {
				if (
					! [ 'core/paragraph', 'core/heading' ].includes( blockType.name ) ||
					attributes.anchor
				) {
					return attributes;
				}

				const holder = document.createElement( 'div' );
				holder.innerHTML = innerHTML;

				const id = holder.firstElementChild?.id;

				return id ? { ...attributes, anchor: id } : attributes;
			}
		);

		try {
			return callback();
		} finally {
			removeFilter( 'blocks.getBlockAttributes', namespace );
		}
	};

	// Removes the HTML comments from the text of Shortcode blocks.
	const cleanShortcodes = ( block ) => {
		if ( block.name === 'core/shortcode' ) {
			block.attributes.text = stripComments( block.attributes.text || '' );
		}

		block.innerBlocks.forEach( cleanShortcodes );

		return block;
	};

	const isObject = ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value );

	const mergeAttributes = ( target, source ) => {
		const merged = { ...target };

		Object.entries( source || {} ).forEach( ( [ key, value ] ) => {
			merged[ key ] =
				isObject( value ) && isObject( merged[ key ] )
					? mergeAttributes( merged[ key ], value )
					: value;
		} );

		return merged;
	};

	const applyAttributes = ( block, apply ) => {
		if ( keptBlocks.has( block ) ) {
			return block;
		}

		let attributes = mergeAttributes( {}, apply[ block.name ] );

		if ( block.name === 'core/heading' ) {
			attributes = mergeAttributes(
				attributes,
				apply[ `core/heading:${ block.attributes.level }` ]
			);
		}

		return cloneBlock(
			block,
			mergeAttributes( block.attributes, attributes ),
			block.innerBlocks.map( ( inner ) => applyAttributes( inner, apply ) )
		);
	};

	const hasInlineImages = ( element ) => {
		if ( element.tagName !== 'P' ) {
			return false;
		}

		const images = element.querySelectorAll( 'img' ).length;
		const text = element.textContent.replace( /\s/g, '' );

		return images > 1 || ( images === 1 && text !== '' );
	};

	const isBlankText = ( node ) =>
		node.nodeType === 3 && /^[ \f\n\r\t\v ]*$/.test( node.nodeValue );

	// Reads a `more`, `nextpage` or `noteaser` comment.
	const commentMarker = ( node ) => {
		if ( node.nodeType !== 8 ) {
			return null;
		}

		const data = node.data.trim();

		if ( data === 'nextpage' || data === 'noteaser' ) {
			return { kind: data, text: '' };
		}

		const match = /^more(?:\s+([\s\S]*))?$/.exec( data );

		return match ? { kind: 'more', text: ( match[ 1 ] || '' ).trim() } : null;
	};

	// The comment a node stands for: itself, or the only comment in a paragraph.
	const loneComment = ( node ) => {
		if ( node.nodeType === 8 ) {
			return node;
		}

		if ( node.nodeType !== 1 || node.tagName !== 'P' ) {
			return null;
		}

		let found = null;

		for ( const child of Array.from( node.childNodes ) ) {
			if ( isBlankText( child ) ) {
				continue;
			}

			if ( child.nodeType !== 8 || found ) {
				return null;
			}

			found = child;
		}

		return found;
	};

	// Paragraph holding the nodes, with the source paragraph's attributes.
	const paragraphPiece = ( source, nodes ) => {
		const isBreak = ( node ) =>
			node.nodeType === 1 && node.tagName === 'BR';

		if ( nodes.every( ( node ) => isBlankText( node ) || isBreak( node ) ) ) {
			return [];
		}

		const paragraph = document.createElement( 'p' );

		if ( source.nodeType === 1 ) {
			Array.from( source.attributes ).forEach( ( attribute ) =>
				paragraph.setAttribute( attribute.name, attribute.value )
			);
		}

		nodes.forEach( ( node ) => paragraph.appendChild( node ) );

		return [ { html: paragraph.outerHTML } ];
	};

	// Whether a `noteaser` comment follows the `more` comment at `index`:
	// anywhere later in the paragraph, or as a later top-level node, which
	// this removes.
	const readNoTeaser = ( node, nodes, index ) => {
		for ( let next = index + 1; next < nodes.length; next++ ) {
			const marker = commentMarker( nodes[ next ] );

			if ( marker && marker.kind === 'noteaser' ) {
				return true;
			}
		}

		let following = node.nextSibling;

		while ( following ) {
			const comment = loneComment( following );
			const marker = comment ? commentMarker( comment ) : null;
			const after = following.nextSibling;

			if ( marker && marker.kind === 'noteaser' ) {
				following.remove();
				return true;
			}

			following = after;
		}

		return false;
	};

	// Splits a top-level comment or paragraph at its `more` and `nextpage`
	// comments, as `RawHandler::split_at_markers()` does.
	const splitAtMarkers = ( node, nodes ) => {
		const pieces = [];
		let segment = [];
		let found = false;

		for ( let index = 0; index < nodes.length; index++ ) {
			const child = nodes[ index ];
			const marker = commentMarker( child );

			if ( child.nodeType !== 8 ) {
				segment.push( child );
				continue;
			}

			if ( ! marker || marker.kind === 'noteaser' ) {
				continue;
			}

			found = true;
			pieces.push( ...paragraphPiece( node, segment ) );
			segment = [];

			if ( marker.kind === 'more' ) {
				pieces.push( {
					block: createBlock( 'core/more', {
						customText: marker.text,
						noTeaser: readNoTeaser( node, nodes, index ),
					} ),
				} );
			} else {
				pieces.push( { block: createBlock( 'core/nextpage' ) } );
			}
		}

		if ( ! found ) {
			return null;
		}

		pieces.push( ...paragraphPiece( node, segment ) );

		return pieces;
	};

	// Converts the `more` and `nextpage` comments in HTML into blocks, and
	// runs the HTML between them through the raw handler.
	const rawWithComments = ( html ) => {
		const holder = document.createElement( 'div' );
		holder.innerHTML = html;

		const items = [];
		let run = '';

		const endRun = () => {
			if ( run ) {
				items.push( { html: run } );
			}
			run = '';
		};

		Array.from( holder.childNodes ).forEach( ( node ) => {
			if ( ! node.parentNode ) {
				return;
			}

			let nodes = null;

			if ( node.nodeType === 8 ) {
				nodes = [ node ];
			} else if ( node.nodeType === 1 && node.tagName === 'P' ) {
				nodes = Array.from( node.childNodes );
			}

			const pieces = nodes ? splitAtMarkers( node, nodes ) : null;

			if ( ! pieces ) {
				if ( node.nodeType === 1 ) {
					run += node.outerHTML;
				} else if ( node.nodeType === 3 ) {
					const text = document.createElement( 'div' );
					text.textContent = node.textContent;
					run += text.innerHTML;
				}
				return;
			}

			pieces.forEach( ( piece ) => {
				if ( piece.html ) {
					run += piece.html;
					return;
				}
				endRun();
				items.push( piece );
			} );
		} );

		endRun();

		return items.flatMap( ( item ) =>
			item.block
				? [ item.block ]
				: withAnchors( () =>
						rawHandler( {
							// The editor parses HTML that holds a block delimiter
							// as serialized blocks; the plugin removes the
							// delimiters that do not pair up.
							HTML: mergeTableSections(
								item.html.replace( /<!--\s*\/?wp:[\s\S]*?-->/g, '' )
							),
						} )
				  ).map( cleanShortcodes )
		);
	};

	const rawToBlocks = ( html ) => {
		const segments = blockSegments( html );

		if ( segments ) {
			return segmentBlocks( segments );
		}

		const container = document.createElement( 'div' );
		container.innerHTML = html;

		const blocks = [];
		let pending = '';

		const flush = () => {
			if ( pending.trim() ) {
				blocks.push( ...rawWithComments( pending ) );
			}
			pending = '';
		};

		Array.from( container.childNodes ).forEach( ( node ) => {
			if (
				node.nodeType === 1 &&
				node.tagName === 'DIV' &&
				node.className
			) {
				flush();
				blocks.push( ...groupBlock( node ) );
				return;
			}

			const video = sourceVideo( node );

			if ( video ) {
				flush();
				blocks.push(
					...parse(
						`<!-- wp:html -->\n${ video.outerHTML }\n<!-- /wp:html -->`
					)
				);
				return;
			}

			if (
				node.nodeType === 1 &&
				hasInlineImages( node ) &&
				! hasBlockShortcode( node )
			) {
				flush();

				const textAlign = node.style.textAlign;

				blocks.push(
					createBlock( 'core/paragraph', {
						content: node.innerHTML,
						...( textAlign
							? { style: { typography: { textAlign } } }
							: {} ),
					} )
				);
				return;
			}

			if ( node.nodeType === 1 ) {
				pending += node.outerHTML;
			} else if ( node.nodeType === 3 ) {
				const text = document.createElement( 'div' );
				text.textContent = node.textContent;
				pending += text.innerHTML;
			} else if ( commentMarker( node ) ) {
				pending += `<!--${ node.data }-->`;
			}
		} );

		flush();

		return blocks;
	};

	const buildBlocks = ( specs ) =>
		specs.flatMap( ( spec ) => {
			if ( typeof spec.raw === 'string' ) {
				const apply = isObject( spec.apply ) ? spec.apply : {};

				return rawToBlocks( spec.raw ).map( ( block ) =>
					applyAttributes( block, apply )
				);
			}

			if ( ! getBlockType( spec.name ) ) {
				return [];
			}

			if ( spec.name === 'core/html' ) {
				return parse(
					`<!-- wp:html -->\n${
						spec.attributes?.content ?? ''
					}\n<!-- /wp:html -->`
				);
			}

			return [
				createBlock(
					spec.name,
					spec.attributes,
					buildBlocks( spec.innerBlocks || [] )
				),
			];
		} );

	window.diviMigrationReference = ( specs ) =>
		window.wp.blocks.serialize( buildBlocks( specs ) );
} )();
