/**
 * Collapsible "used tool X" chip. Future: changeset preview cards render here too.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Icon,
	chevronDown,
	chevronRight,
	check,
	caution,
} from '@wordpress/icons';
import { Spinner } from '@wordpress/components';

function pretty( value ) {
	if ( value === null || value === undefined || value === '' ) {
		return '';
	}
	if ( typeof value === 'string' ) {
		try {
			return JSON.stringify( JSON.parse( value ), null, 2 );
		} catch ( e ) {
			return value;
		}
	}
	return JSON.stringify( value, null, 2 );
}

export default function ToolChip( { part } ) {
	const [ open, setOpen ] = useState( false );
	const label = part.label || part.name;
	const input = pretty( part.input );
	const hasInput = input && input !== '{}' && input !== '[]';

	let status;
	if ( part.state === 'running' || part.state === 'pending' ) {
		status = part.state === 'running' ? <Spinner /> : null;
	} else if ( part.state === 'error' ) {
		status = (
			<Icon icon={ caution } size={ 16 } className="aiops-tool__err" />
		);
	} else {
		status = <Icon icon={ check } size={ 16 } className="aiops-tool__ok" />;
	}
	const headline =
		part.state === 'running'
			? sprintf(
					/* translators: %s: tool name */
					__( 'Using tool: %s', 'addsynapse-store-assistant' ),
					label
			  )
			: sprintf(
					/* translators: %s: tool name */
					__( 'Used tool: %s', 'addsynapse-store-assistant' ),
					label
			  );

	return (
		<div className={ `aiops-tool is-${ part.state }` }>
			<button
				type="button"
				className="aiops-tool__head"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				<Icon icon={ open ? chevronDown : chevronRight } size={ 18 } />
				<span className="aiops-tool__label">{ headline }</span>
				{ status }
			</button>
			{ open && (
				<div className="aiops-tool__body">
					<div className="aiops-tool__section">
						<span className="aiops-tool__caption">
							{ __( 'Input', 'addsynapse-store-assistant' ) }
						</span>
						<pre>
							{ hasInput
								? input
								: __(
										'(no parameters)',
										'addsynapse-store-assistant'
								  ) }
						</pre>
					</div>
					{ part.result !== null && part.result !== undefined && (
						<div className="aiops-tool__section">
							<span className="aiops-tool__caption">
								{ part.state === 'error'
									? __(
											'Error',
											'addsynapse-store-assistant'
									  )
									: __(
											'Result',
											'addsynapse-store-assistant'
									  ) }
							</span>
							<pre>{ pretty( part.result ) }</pre>
						</div>
					) }
				</div>
			) }
		</div>
	);
}
