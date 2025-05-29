import { useContext } from "react";
import SbUtils from "../../../Utils/SbUtils";
import { __ } from "@wordpress/i18n";
import Button from "../../Common/Button";


const SourceLimitExceededModal = (props) => {
	const currentContext =  SbUtils.getCurrentContext();
    const {
        sbCustomizer,
		addSModal,
		freeRetModal,
		isPro,
    } = useContext( currentContext );

	const titleText = isPro
		? __('You can only add 2 Google and 5 Yelp sources','reviews-feed')
		: __('You can only add 1 source','reviews-feed')

	const descriptionText = isPro
		? __('Due to paid API pricing by Yelp and Google, we only offer a limited amount of Google or Yelp source. To add more, you can add an API key for each platform.','reviews-feed')
		: __('Due to paid API pricing by Yelp and Google, we only offer a single Google or Yelp source on the free plan. To add more, you can add an API key or upgrade your plan.','reviews-feed')


	const openAddApiKeyModal = () => {
		addSModal.setModalType('freeRetriever')
		freeRetModal?.setRetModalType('addApiKey')
	}

	return (
		<div className="sb-modal-message sb-flex sb-flex-center sb-fs" data-style="fixed-elements">
			<div className="sb-modal-msg-heading sb-flex sb-fs">
				<h4 className="sb-h4">
					{__('Add a Source','reviews-feed')}
				</h4>
			</div>
			<div className="sb-modal-msg-icon sb-flex sb-flex-center" data-type="pr-icons">
				<div className="sb-modal-m-icon sb-flex sb-flex-center" data-type="style3">
					{
						SbUtils.printIcon('google-provider', 'sb-source-svg', false, 24)
					}
				</div>
				<div className="sb-modal-m-icon sb-flex sb-flex-center" data-type="style3">
					{
						SbUtils.printIcon('yelp-provider', 'sb-source-svg', false, 24)
					}
				</div>
				{
					!isPro &&
					<div className="sb-modal-m-num sb-flex sb-flex-center">1</div>
				}
			</div>
			<div className="sb-modal-msg-content sb-flex sb-flex-center">
				<strong
					className="sb-h4"
					dangerouslySetInnerHTML={{__html : titleText}}
				>
				</strong>
				<span
					className="sb-text-small sb-dark2-text sb-standard-p"
				>
					{__('To add another source, please delete any existing sources.','reviews-feed')}
					<br/>
					<span
						dangerouslySetInnerHTML={{__html : descriptionText}}
					>
					</span>
				</span>
			</div>
			<div className="sb-modal-msg-btns sb-flex sb-fs">
				{
					isPro &&
					<>
						<Button
							type='secondary'
							size='medium'
							boxshadow='false'
							text={ __( 'Back', 'sb-customizer' ) }
							onClick={() => {
								props.onCancel()
							}}
						/>
						<Button
							type='primary'
							size='medium'
							icon='key'
							icon-position='left'
							boxshadow='false'
							iconSize='16'
							text={ __( 'Add an API Key', 'sb-customizer' ) }
							onClick={() => {
								openAddApiKeyModal()
							}}
						/>
					</>
				}
				{
					!isPro &&
					<>
						<Button
							type='secondary'
							size='medium'
							boxshadow='false'
							text={ __( 'Add an API Key', 'sb-customizer' ) }
							onClick={() => {
								openAddApiKeyModal()
							}}
						/>
						<Button
							type='primary'
							size='medium'
							icon='arrow-diag'
							icon-position='right'
							boxshadow='false'
							iconSize='9'
							text={ __( 'Upgrade your Plan', 'sb-customizer' ) }
							onClick={() => {
								alert("Upgrade Plan")
							}}
						/>
					</>
				}
			</div>
		</div>
	)
}
export default SourceLimitExceededModal;