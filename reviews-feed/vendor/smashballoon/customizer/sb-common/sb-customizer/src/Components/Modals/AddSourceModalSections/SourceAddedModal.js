import { useContext } from "react";
import SbUtils from "../../../Utils/SbUtils";
import { __ } from "@wordpress/i18n";
import Button from "../../Common/Button";

const SourceAddedModal = (props) => {
	const currentContext =  SbUtils.getCurrentContext();
    const {
		addSModal,
		freeRetModal,
		isPro,
    } = useContext( currentContext );

	const descriptionText = isPro
		? __('Due to API pricing by Google and Yelp, we update your sources once a week. To update them more often, you can add a Google and Yelp API Key.','reviews-feed')
		: __('Due to API pricing by Google and Yelp, we can only fetch the latest 10 reviews in the free plan. To fetch more or fetch again, you can add an API key or upgrade your plan.','reviews-feed')

	return (
		<div className="sb-modal-message sb-flex sb-flex-center sb-fs">
			<div className="sb-modal-msg-icon sb-flex sb-flex-center">
				<div className="sb-modal-m-icon sb-flex sb-flex-center sb-svg-i" data-type="style2">
					{
						SbUtils.printIcon('success', '', false, 25)
					}
				</div>
			</div>
			<div className="sb-modal-msg-content sb-flex sb-flex-center">
				<strong className="sb-h4">
					{
						__('Account added successfully','reviews-feed')
					}
				</strong>
				<span
					className="sb-text-small sb-dark2-text sb-standard-p"
					dangerouslySetInnerHTML={{__html : descriptionText}}
				>
				</span>
			</div>
			<div className="sb-modal-msg-btns sb-flex">
				<Button
					type='primary'
					size='medium'
					icon='success'
					icon-position='left'
					boxshadow='false'
					iconSize='13'
					text={ __( 'Okay', 'sb-customizer' ) }
					onClick={() => {
						props.onCancel()
						addSModal.setModalType('addSource')
						freeRetModal.setRetModalType('addApiKey')
					}}
				/>
				{
					isPro &&
					<Button
						type='secondary'
						size='medium'
						boxshadow='false'
						text={ __( 'Add API key', 'sb-customizer' ) }
						onClick={() => {
							freeRetModal.setRetModalType('addApiKey')
						}}
					/>
				}
				{
					!isPro &&
					<Button
						type='secondary'
						size='medium'
						icon='arrow-diag'
						icon-position='right'
						boxshadow='false'
						iconSize='9'
						text={ __( 'Upgrade Plan', 'sb-customizer' ) }
						onClick={() => {
							SbUtils.upgradePlanAction()
						}}
					/>
				}
			</div>
		</div>
	)
}

export default SourceAddedModal;