import { __ } from "@wordpress/i18n";
import Button from "../../../../Common/Button";
import SbUtils from "../../../../../Utils/SbUtils";
import { useContext } from "react";

const EmptyState = () => {
	const currentContext = SbUtils.getCurrentContext();
	const {
		sbCustomizer,
		subFrModal
    } = useContext( currentContext );

	return (
		<section
			className='sb-submform-empty-ctn sb-fs'
		>
			<section className='sb-submform-empty-insider'>
				<div className='sb-submform-empty-icons'>
					<div className='sb-submform-sicon'>{SbUtils.printIcon('wpforms', false, false, 36)}</div>
					<div className='sb-submform-sicon'>{SbUtils.printIcon('formidable', false, false, 36)}</div>
					<div className='sb-submform-sicon'>{SbUtils.printIcon('edd', false, false, 36)}</div>
				</div>
				<div className='sb-submform-empty-text'>
					<strong>{__('Get reviews directly from your audience','reviews-feed')}</strong>
					<span>{__('Connect forms (WPforms and Formidable are supported) or EDD to this collection and watch reviews gather automatically.','reviews-feed')}</span>
				</div>
				<div className='sb-submform-empty-actions'>
					<Button
						icon='link'
						size='medium'
						type='primary'
						onClick={ () => {
							subFrModal?.setFormSubmissionModalActive(true)
						}}
						text={ __( 'Connect a Form', 'sb-customizer' ) }
					/>
					<Button
						size='medium'
						type='secondary'
						link='https://smashballoon.com/doc/using-wpforms-formidable-reviews/'
						target='_blank'
						text={ __( 'Learn More', 'sb-customizer' ) }
					/>
				</div>
			</section>
		</section>
	)
}

export default EmptyState;