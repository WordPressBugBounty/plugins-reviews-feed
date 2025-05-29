import { __ } from "@wordpress/i18n";
import SbUtils from "../../../Utils/SbUtils";
import Button from "../../Common/Button";
import { useContext } from "react";

const VerifyEmailModal = (props) => {
	const currentContext =  SbUtils.getCurrentContext();
    const {
        sbCustomizer,
    } = useContext( currentContext );

	return (
		<div className="sb-modal-message sb-flex sb-flex-center sb-fs">
			<div className="sb-modal-msg-icon sb-flex sb-flex-center">
				<div className="sb-modal-m-icon sb-flex sb-flex-center sb-svg-i" data-type="style1">
					{
						SbUtils.printIcon('verify-email', '', false, 25)
					}
				</div>
			</div>
			<div className="sb-modal-msg-content sb-flex sb-flex-center">
				<strong className="sb-h4">
					{
						__('Please verify your email','reviews-feed')
					}
				</strong>
				<span className="sb-text-small sb-dark2-text sb-standard-p">
					{
						__("Before you can add a source, we need to make sure it's you. Please verify your email. It takes less than 2 minutes.", 'reviews-feed')
					}
				</span>
			</div>
			<div className="sb-modal-msg-btns sb-flex">
				 <Button
					type='primary'
					size='medium'
					icon='arrow-diag'
				 	icon-position='right'
					boxshadow='false'
					iconSize='11'
					text={ __( 'Verify Email', 'sb-customizer' ) }
					link={sbCustomizer?.emailVerificationURL}
                />
			</div>
		</div>
	)
}

export default VerifyEmailModal;