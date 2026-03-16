import { __ } from '@wordpress/i18n';

export default function JobEvents( { events = [] } ) {
	if ( ! events.length ) {
		return null;
	}

	return (
		<section
			className="lift-events"
			aria-label={ __( 'Job events', 'lift-teleport' ) }
		>
			<h3>{ __( 'Latest events', 'lift-teleport' ) }</h3>
			<ul>
				{ events.slice( 0, 8 ).map( ( event ) => (
					<li key={ event.id }>
						<strong>{ event.level }</strong>
						<span>{ event.message }</span>
					</li>
				) ) }
			</ul>
		</section>
	);
}
