import { __ } from "@wordpress/i18n";
import SbUtils from "../../Utils/SbUtils";
import ModalContainer from "../Global/ModalContainer";

const RedirectingModal = (props) => {

	return (
		<ModalContainer
			size='small'
            closebutton={false}
        >
			<div className='sb-redirecting-modal-ctn sb-fs'>
				<div className='sb-redirecting-modal-icon sb-fs'>
					<div className='sb-redirecting-modal-icon-anim'>
						{
							[1,2,3].map(el => {
								return (
									<div
										key={el}
										className='sb-tr-1'
										style={{animationDelay : (el * 0.2) + 's'}}
									></div>
								)
							})
						}
					</div>
				</div>
				<div className='sb-redirecting-modal-text sb-fs'>
					{
						props?.heading &&
						<h4 className='sb-h4 sb-fs' dangerouslySetInnerHTML={{__html: props?.heading }}></h4>
					}
					{
						props?.text &&
						<p className='sb-standard-p sb-dark2-text sb-fs' dangerouslySetInnerHTML={{__html: props?.text }}></p>
					}
				</div>
			</div>
		</ModalContainer>
	)

}

export default RedirectingModal;