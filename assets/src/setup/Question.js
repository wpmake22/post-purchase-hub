/**
 * A screen's heading and its one paragraph of explanation.
 *
 * Every step is the same shape — a question, why it is being asked, and the
 * controls that answer it — so the shape lives here rather than being retyped
 * on seven screens with seven slightly different headings.
 */

/**
 * Renders a step's frame.
 *
 * @param {Object}  props          Component props.
 * @param {string}  props.title    The question being asked.
 * @param {string}  props.help     Why it is being asked.
 * @param {Element} props.children The controls that answer it.
 * @return {Element} The framed step.
 */
export default function Question( { title, help, children } ) {
	return (
		<section className="pph-setup__question">
			<h1 className="pph-setup__title">{ title }</h1>
			{ help && <p className="pph-setup__help">{ help }</p> }
			<div className="pph-setup__body">{ children }</div>
		</section>
	);
}
