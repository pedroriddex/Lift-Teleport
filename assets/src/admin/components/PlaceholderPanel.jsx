import { __ } from '@wordpress/i18n';

export default function PlaceholderPanel( { title = '' } ) {
	return (
		<section className="lift-placeholder-panel" aria-label={ title }>
			{ title ? (
				<p className="lift-placeholder-kicker lift-swiss-kicker">
					{ title }
				</p>
			) : null }
			<h3>{ __( 'We are working on this', 'lift-teleport' ) }</h3>
			<p>
				{ __(
					'This section is under active development.',
					'lift-teleport'
				) }
			</p>
		</section>
	);
}
