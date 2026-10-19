/**
 * Safe markdown renderer.
 *
 * react-markdown builds React elements (no innerHTML). Raw HTML in the text is
 * NOT interpreted (rendered as text) because rehype-raw is intentionally absent,
 * and link URLs go through react-markdown's default safe URL transform.
 */
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { memo } from '@wordpress/element';

const components = {
	a: ( { node, children, ...props } ) => (
		<a { ...props } target="_blank" rel="noopener noreferrer nofollow">
			{ children }
		</a>
	),
	table: ( { node, children, ...props } ) => (
		<div className="aiops-md-table">
			<table { ...props }>{ children }</table>
		</div>
	),
	img: ( { alt } ) => (
		<span className="aiops-md-img">[{ alt || 'image' }]</span>
	),
};

function Markdown( { text } ) {
	return (
		<div className="aiops-md">
			<ReactMarkdown
				remarkPlugins={ [ remarkGfm ] }
				components={ components }
			>
				{ text }
			</ReactMarkdown>
		</div>
	);
}

export default memo( Markdown );
