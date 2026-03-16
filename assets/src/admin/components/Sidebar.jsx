import { __ } from '@wordpress/i18n';

const NAV_ITEMS = [
	{
		id: 'export-import',
		label: __( 'Export / Import', 'lift-teleport' ),
	},
	{
		id: 'automation',
		label: __( 'Automation', 'lift-teleport' ),
	},
	{
		id: 'backup',
		label: __( 'Backups', 'lift-teleport' ),
	},
	{
		id: 'diagnostics',
		label: __( 'Diagnostics', 'lift-teleport' ),
	},
	{
		id: 'unzipper',
		label: __( 'Unzipper', 'lift-teleport' ),
	},
	{
		id: 'settings',
		label: __( 'Settings', 'lift-teleport' ),
	},
];

export default function Sidebar( {
	activePanel = 'export-import',
	onSelect = () => {},
} ) {
	return (
		<aside
			className="lift-sidebar lift-rail-minimal"
			aria-label="Lift Teleport navigation"
		>
			{ NAV_ITEMS.map( ( item ) => {
				const isActive = item.id === activePanel;
				return (
					<button
						key={ item.id }
						type="button"
						className={ `lift-sidebar-item ${
							isActive ? 'is-active' : ''
						}` }
						aria-current={ isActive ? 'page' : undefined }
						onClick={ () => onSelect( item.id ) }
					>
						{ item.label }
					</button>
				);
			} ) }
		</aside>
	);
}
