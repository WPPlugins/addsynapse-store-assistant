/**
 * Groups stored API-level messages into display turns.
 *
 * user rows → a user turn; consecutive assistant + tool rows → one assistant turn
 * whose parts are text and tool calls (tool results matched by tool_use_id).
 *
 * @param {Array} messages Messages from the REST API.
 * @return {Array} Turns.
 */
export function buildTurns( messages ) {
	const turns = [];
	let current = null;
	const toolIndex = {};

	messages.forEach( ( m ) => {
		if ( m.role === 'user' ) {
			current = null;
			const text = m.blocks
				.filter( ( b ) => b.type === 'text' )
				.map( ( b ) => b.text )
				.join( '\n\n' );
			turns.push( {
				key: `u${ m.id }`,
				role: 'user',
				text,
				createdAt: m.created_at,
				pending: !! m.pending,
				selection: m.selection || null,
				image: m.image || null,
			} );
			return;
		}
		if ( ! current ) {
			current = {
				key: `a${ m.id }`,
				role: 'assistant',
				parts: [],
				createdAt: m.created_at,
				status: 'complete',
			};
			turns.push( current );
		}
		current.createdAt = m.created_at;
		if ( m.role === 'assistant' ) {
			if ( m.status && m.status !== 'complete' ) {
				current.status = m.status;
			} else {
				current.status = 'complete';
			}
			m.blocks.forEach( ( b ) => {
				if ( b.type === 'text' && b.text.trim() ) {
					current.parts.push( { type: 'text', text: b.text } );
				} else if ( b.type === 'tool_use' ) {
					const part = {
						type: 'tool',
						id: b.id,
						name: b.name,
						label: b.label,
						input: b.input,
						state: 'pending',
						result: null,
						data: null,
					};
					toolIndex[ b.id ] = part;
					current.parts.push( part );
				}
			} );
		} else if ( m.role === 'tool' ) {
			m.blocks.forEach( ( b ) => {
				const part = toolIndex[ b.tool_use_id ];
				if ( part ) {
					part.state = b.is_error ? 'error' : 'done';
					part.data = b.data !== undefined ? b.data : null;
					part.result = b.data !== undefined ? b.data : b.content;
				}
			} );
		}
	} );

	return turns;
}

/**
 * Plain text of an assistant turn (for copy).
 *
 * @param {Object} turn Turn.
 * @return {string} Text.
 */
export function turnText( turn ) {
	if ( turn.role === 'user' ) {
		return turn.text;
	}
	return turn.parts
		.filter( ( p ) => p.type === 'text' )
		.map( ( p ) => p.text )
		.join( '\n\n' );
}
