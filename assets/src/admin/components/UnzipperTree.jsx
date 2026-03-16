import { __ } from '@wordpress/i18n';

export default function UnzipperTree( {
	entries = [],
	hasMore = false,
	onLoadMore = () => {},
	loading = false,
} ) {
	const rows = buildRows( entries );

	return (
		<section className="lift-unzipper-tree" aria-live="polite">
			<header className="lift-unzipper-tree-head">
				<h3>{ __( 'Package explorer', 'lift-teleport' ) }</h3>
				<span>
					{ entries.length } { __( 'loaded', 'lift-teleport' ) }
				</span>
			</header>

			{ rows.length ? (
				<ul className="lift-tree-list">
					{ rows.map( ( row ) => (
						<li
							key={ `${ row.path }-${ row.type }` }
							className="lift-tree-row"
							style={ {
								paddingLeft: `${
									Math.max( 0, row.depth ) * 14
								}px`,
							} }
						>
							<span className="lift-tree-icon" aria-hidden="true">
								{ row.type === 'dir' ? '▸' : '•' }
							</span>
							<code>{ row.name }</code>
							<small>{ row.path }</small>
						</li>
					) ) }
				</ul>
			) : (
				<p className="lift-empty-copy">
					{ __(
						'No entries available yet. Run a scan to inspect package contents.',
						'lift-teleport'
					) }
				</p>
			) }

			{ hasMore ? (
				<button
					type="button"
					className="lift-cta lift-cta-secondary"
					onClick={ onLoadMore }
					disabled={ loading }
				>
					{ loading
						? __( 'Loading more…', 'lift-teleport' )
						: __( 'Load more', 'lift-teleport' ) }
				</button>
			) : null }
		</section>
	);
}

function buildRows( entries ) {
	if ( ! Array.isArray( entries ) ) {
		return [];
	}

	return entries
		.map( ( entry ) => {
			const path = String( entry?.path || '' ).replace( /^\/+/, '' );
			if ( ! path ) {
				return null;
			}

			const segments = path.split( '/' ).filter( Boolean );
			const name = String(
				entry?.name || segments[ segments.length - 1 ] || ''
			);
			return {
				path,
				name,
				type: String( entry?.type || 'file' ),
				depth: Math.max( 0, segments.length - 1 ),
			};
		} )
		.filter( Boolean )
		.sort( ( left, right ) => left.path.localeCompare( right.path ) );
}
