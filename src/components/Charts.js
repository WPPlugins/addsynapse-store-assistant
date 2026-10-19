/**
 * Tiny dependency-free charts for report results (SVG line/area + CSS bars).
 *
 * Drawn in the browser from the data the result card already has (zero AI cost). The table
 * next to each chart stays the accessible fallback; charts carry a text summary (aria-label).
 * Colours come from the admin theme (`--aiops-accent`); bars are HTML so RTL mirrors them.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const settings = window.addsynapseAssistant || {};
const locale = settings.locale || undefined;

const HEIGHT = 190;
const PAD = { top: 12, right: 12, bottom: 26, left: 56 };

/**
 * "Nice" axis maximum and step (1, 2, 2.5, 5 × 10^n).
 *
 * @param {number} max   Largest value.
 * @param {number} ticks Wanted number of steps.
 * @return {{max:number, step:number}} Axis.
 */
export function niceAxis( max, ticks = 4 ) {
	if ( ! ( max > 0 ) ) {
		return { max: 1, step: 1 };
	}
	const raw = max / ticks;
	const pow = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
	const step =
		[ 1, 2, 2.5, 5, 10 ]
			.map( ( m ) => m * pow )
			.find( ( s ) => s >= raw ) || 10 * pow;
	return { max: Math.ceil( max / step ) * step, step };
}

function compact( value, currency ) {
	try {
		return new Intl.NumberFormat( locale, {
			notation: 'compact',
			maximumFractionDigits: 1,
			style: currency ? 'currency' : 'decimal',
			currency: currency || undefined,
		} ).format( value );
	} catch ( e ) {
		return String( value );
	}
}

/**
 * Bucket label for the axis / tooltip.
 *
 * @param {string}  label  Y-m-d (day/week start) or Y-m (month).
 * @param {string}  bucket day|week|month.
 * @param {boolean} long   Tooltip form (with year / "week of").
 * @return {string} Text.
 */
export function bucketLabel( label, bucket, long = false ) {
	const d = new Date(
		bucket === 'month' ? `${ label }-01T00:00:00` : `${ label }T00:00:00`
	);
	if ( Number.isNaN( d.getTime() ) ) {
		return label;
	}
	const opts =
		bucket === 'month'
			? { month: long ? 'long' : 'short', year: 'numeric' }
			: {
					day: 'numeric',
					month: 'short',
					...( long ? { year: 'numeric' } : {} ),
			  };
	let text;
	try {
		text = new Intl.DateTimeFormat( locale, opts ).format( d );
	} catch ( e ) {
		text = label;
	}
	return long && bucket === 'week'
		? sprintf(
				/* translators: %s: first day of a 7-day period */
				__( '7 days from %s', 'addsynapse-store-assistant' ),
				text
		  )
		: text;
}

function useWidth( fallback = 600 ) {
	const ref = useRef( null );
	const [ width, setWidth ] = useState( fallback );
	useEffect( () => {
		const el = ref.current;
		if ( ! el || typeof window.ResizeObserver === 'undefined' ) {
			return undefined;
		}
		const ro = new window.ResizeObserver( ( entries ) => {
			const w = Math.round( entries[ 0 ].contentRect.width );
			if ( w > 0 ) {
				setWidth( w );
			}
		} );
		ro.observe( el );
		return () => ro.disconnect();
	}, [] );
	return [ ref, width ];
}

/**
 * Sales over time (product-report sales_summary `series`): area chart with a revenue/orders
 * toggle, crosshair + tooltip on hover, arrow keys when focused.
 *
 * @param {Object} props            Props.
 * @param {Object} props.series     {bucket, labels[], revenue[], orders[], items[], complete_from?}.
 * @param {string} props.currency   ISO currency.
 * @param {Object} props.formatters {money, number} from lib/format.
 * @return {JSX.Element|null} Chart.
 */
export function SalesChart( { series, currency, formatters } ) {
	const [ metric, setMetric ] = useState( 'revenue' );
	const [ active, setActive ] = useState( null );
	const [ ref, width ] = useWidth();
	const labels = ( series && series.labels ) || [];
	if ( labels.length < 2 ) {
		return null;
	}
	const values = ( series[ metric ] || [] ).map( ( v ) => Number( v ) || 0 );
	const fmt = ( v ) =>
		metric === 'revenue'
			? formatters.money( v, currency )
			: formatters.number( v );
	const axis = niceAxis( Math.max( 0, ...values ) );
	const w = Math.max( 240, width );
	const iw = w - PAD.left - PAD.right;
	const ih = HEIGHT - PAD.top - PAD.bottom;
	const x = ( i ) => PAD.left + ( iw * i ) / ( labels.length - 1 );
	const y = ( v ) => PAD.top + ih - ( ih * v ) / axis.max;
	const points = values.map( ( v, i ) => `${ x( i ) },${ y( v ) }` );
	const line = `M${ points.join( 'L' ) }`;
	const area = `${ line }L${ x( labels.length - 1 ) },${ y( 0 ) }L${ x(
		0
	) },${ y( 0 ) }Z`;
	const ticks = [];
	for ( let t = 0; t <= axis.max + axis.step / 2; t += axis.step ) {
		ticks.push( t );
	}
	// At most ~6 x labels, always the first and the last.
	const every = Math.max(
		1,
		Math.ceil( labels.length / Math.max( 2, Math.floor( iw / 90 ) ) )
	);
	const incomplete = series.complete_from
		? labels.indexOf( series.complete_from )
		: -1;
	const shadeTo =
		series.complete_from === '' ? labels.length - 1 : incomplete;

	let peak = 0;
	values.forEach( ( v, i ) => {
		if ( v > values[ peak ] ) {
			peak = i;
		}
	} );
	const total = values.reduce( ( a, b ) => a + b, 0 );
	const title =
		metric === 'revenue'
			? __( 'Revenue over time', 'addsynapse-store-assistant' )
			: __( 'Orders over time', 'addsynapse-store-assistant' );
	// Range ends as plain dates (a week bucket label would read "7 days from …").
	const edgeBucket = series.bucket === 'month' ? 'month' : 'day';
	const summary = sprintf(
		/* translators: 1: chart title, 2: first period, 3: last period, 4: total, 5: highest value, 6: period of the highest value */
		__(
			'%1$s, %2$s to %3$s. Total %4$s; highest %5$s (%6$s).',
			'addsynapse-store-assistant'
		),
		title,
		bucketLabel( labels[ 0 ], edgeBucket, true ),
		bucketLabel( labels[ labels.length - 1 ], edgeBucket, true ),
		fmt( total ),
		fmt( values[ peak ] ),
		bucketLabel( labels[ peak ], series.bucket, true )
	);

	const pick = ( clientX, rect ) => {
		const rel = ( ( clientX - rect.left ) / rect.width ) * w;
		const i = Math.round(
			( ( rel - PAD.left ) / iw ) * ( labels.length - 1 )
		);
		setActive( Math.min( labels.length - 1, Math.max( 0, i ) ) );
	};
	const onKey = ( e ) => {
		if ( e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' ) {
			return;
		}
		e.preventDefault();
		const cur = active === null ? peak : active;
		setActive(
			Math.min(
				labels.length - 1,
				Math.max( 0, cur + ( e.key === 'ArrowRight' ? 1 : -1 ) )
			)
		);
	};

	return (
		<figure className="aiops-chart">
			<div className="aiops-chart__head">
				<figcaption className="aiops-chart__title">
					{ title }
				</figcaption>
				<div
					className="aiops-chart__toggle"
					role="group"
					aria-label={ __(
						'Chart metric',
						'addsynapse-store-assistant'
					) }
				>
					{ [
						[
							'revenue',
							__( 'Revenue', 'addsynapse-store-assistant' ),
						],
						[
							'orders',
							__( 'Orders', 'addsynapse-store-assistant' ),
						],
					].map( ( [ key, text ] ) => (
						<button
							key={ key }
							type="button"
							className="aiops-chart__toggle-btn"
							aria-pressed={ metric === key }
							onClick={ () => setMetric( key ) }
						>
							{ text }
						</button>
					) ) }
				</div>
			</div>
			<div className="aiops-chart__plot" ref={ ref }>
				<svg
					className="aiops-chart__svg"
					width={ w }
					height={ HEIGHT }
					viewBox={ `0 0 ${ w } ${ HEIGHT }` }
					role="img"
					aria-label={ summary }
					tabIndex={ 0 }
					onPointerMove={ ( e ) =>
						pick(
							e.clientX,
							e.currentTarget.getBoundingClientRect()
						)
					}
					onPointerLeave={ () => setActive( null ) }
					onBlur={ () => setActive( null ) }
					onKeyDown={ onKey }
				>
					<title>{ summary }</title>
					{ shadeTo >= 0 && (
						<rect
							className="aiops-chart__incomplete"
							x={ PAD.left }
							y={ PAD.top }
							width={
								Math.max( 0, x( shadeTo ) - PAD.left ) || 4
							}
							height={ ih }
						/>
					) }
					{ ticks.map( ( t ) => (
						<g key={ t }>
							<line
								className="aiops-chart__grid"
								x1={ PAD.left }
								x2={ w - PAD.right }
								y1={ y( t ) }
								y2={ y( t ) }
							/>
							<text
								className="aiops-chart__tick"
								x={ PAD.left - 6 }
								y={ y( t ) }
								textAnchor="end"
								dominantBaseline="middle"
							>
								{ metric === 'revenue'
									? compact( t, currency )
									: compact( t ) }
							</text>
						</g>
					) ) }
					{ labels.map(
						( l, i ) =>
							( i % every === 0 || i === labels.length - 1 ) &&
							( i === labels.length - 1 ||
								labels.length - 1 - i >= every / 2 ) && (
								<text
									key={ l }
									className="aiops-chart__tick"
									x={ x( i ) }
									y={ HEIGHT - 8 }
									textAnchor={
										// eslint-disable-next-line no-nested-ternary
										i === 0
											? 'start'
											: i === labels.length - 1
											? 'end'
											: 'middle'
									}
								>
									{ bucketLabel( l, series.bucket ) }
								</text>
							)
					) }
					<path className="aiops-chart__area" d={ area } />
					<path className="aiops-chart__line" d={ line } />
					{ active !== null && (
						<g>
							<line
								className="aiops-chart__cross"
								x1={ x( active ) }
								x2={ x( active ) }
								y1={ PAD.top }
								y2={ PAD.top + ih }
							/>
							<circle
								className="aiops-chart__dot"
								cx={ x( active ) }
								cy={ y( values[ active ] ) }
								r={ 4.5 }
							/>
						</g>
					) }
				</svg>
				{ active !== null && (
					<div
						className="aiops-chart__tip"
						style={ {
							left: `${ ( x( active ) / w ) * 100 }%`,
							transform: `translateX(${
								// eslint-disable-next-line no-nested-ternary
								active < labels.length / 4
									? '0'
									: active > ( 3 * labels.length ) / 4
									? '-100%'
									: '-50%'
							})`,
						} }
						aria-live="polite"
					>
						<span className="aiops-chart__tip-label">
							{ bucketLabel(
								labels[ active ],
								series.bucket,
								true
							) }
						</span>
						<strong>{ fmt( values[ active ] ) }</strong>
						{ metric === 'revenue' && series.orders && (
							<span className="aiops-chart__tip-sub">
								{ sprintf(
									/* translators: %s: number of orders */
									__(
										'%s orders',
										'addsynapse-store-assistant'
									),
									formatters.number( series.orders[ active ] )
								) }
							</span>
						) }
					</div>
				) }
			</div>
			{ shadeTo >= 0 && (
				<div className="aiops-chart__note">
					{ series.complete_from
						? sprintf(
								/* translators: %s: first complete period */
								__(
									'Shaded: incomplete figures (partial scan) before %s.',
									'addsynapse-store-assistant'
								),
								bucketLabel(
									series.complete_from,
									series.bucket,
									true
								)
						  )
						: __(
								'All figures are incomplete (partial scan).',
								'addsynapse-store-assistant'
						  ) }
				</div>
			) }
		</figure>
	);
}

/**
 * Horizontal bar list (top N): HTML bars so the layout mirrors in RTL and text stays text.
 * Decorative for screen readers (the table below has the same rows).
 *
 * @param {Object}   props       Props.
 * @param {string}   props.title Caption.
 * @param {Object[]} props.rows  [{key, label, value, text}] — value = number, text = formatted.
 * @param {number}   props.max   Rows shown (default 10).
 * @return {JSX.Element|null} Chart.
 */
export function BarList( { title, rows, max = 10 } ) {
	const shown = ( rows || [] )
		.filter( ( r ) => Number( r.value ) > 0 )
		.slice( 0, max );
	if ( shown.length < 2 ) {
		return null;
	}
	const top = Math.max( ...shown.map( ( r ) => Number( r.value ) ) );
	return (
		<figure className="aiops-chart aiops-bars">
			<figcaption className="aiops-chart__title">{ title }</figcaption>
			<ol className="aiops-bars__list" aria-hidden="true">
				{ shown.map( ( r ) => (
					<li
						className="aiops-bars__row"
						key={ r.key }
						title={ `${ r.label }: ${ r.text }` }
					>
						<span className="aiops-bars__label">{ r.label }</span>
						<span className="aiops-bars__track">
							<span
								className="aiops-bars__bar"
								style={ {
									width: `${ Math.max(
										1,
										( Number( r.value ) / top ) * 100
									) }%`,
								} }
							/>
						</span>
						<span className="aiops-bars__value">{ r.text }</span>
					</li>
				) ) }
			</ol>
		</figure>
	);
}
