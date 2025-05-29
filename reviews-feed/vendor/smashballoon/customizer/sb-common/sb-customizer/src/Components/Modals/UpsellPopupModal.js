import { __ } from "@wordpress/i18n";
import { useContext } from "react";
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";
import ModalContainer from "../Global/ModalContainer";

const UpsellPopupModal = ( props ) => {

    const upsellModal = props.upsellModal;
    const upsellContent = upsellModal.upsellActive !== false && props?.upsellContent[upsellModal.upsellActive] !== undefined ? props?.upsellContent[upsellModal.upsellActive] : null;
    const upsellContentList = [
        __('TripAdvisor & Facebook Support', 'sb-customizer'),
        __('Moderate review feeds', 'sb-customizer'),
        __('Display images and videos', 'sb-customizer'),
        __('One-click templates', 'sb-customizer'),
        __('Advanced filtering', 'sb-customizer'),
        __('Carousel layout', 'sb-customizer'),
        __('Mobile design support', 'sb-customizer'),
        __('Custom icons', 'sb-customizer'),
        __('Pro support', 'sb-customizer')
    ]

    const includedButtons = upsellContent?.buttons ? Object.keys(upsellContent?.buttons) : [];

    return (
        (upsellModal.upsellActive !== false && upsellContent !== null )&&
            <ModalContainer
                size='medium'
                closebutton={true}
                onClose={ () => {
                    upsellModal.setUpsellActive( false )
                } }
            >

                <div className='sb-upsell-modal sb-fs'>
                    <div className='sb-upsell-modal-top sb-fs'>
                        <div className='sb-upsell-modal-heading sb-fs'>
                            <h2 className="sb-fs sb-h2">{ upsellContent.heading }</h2>
                            {
                                upsellContent.description &&
                                <p className="sb-fs sb-text-tiny sb-dark2-text">{ upsellContent.description }</p>
                            }
                            {
                                includedButtons.includes('lite') &&
                                <Button
                                    customClass='sb-upgrade-lite-btn'
                                    size='medium'
                                    text={ __('<span className="sb-reviews-extpp-lite-btn-texts"> Lite Plugin Users get a 50% OFF <span className="sb-reviews-extpp-lite-btn-discount-applied">auto-applied at checkout</span></span>', 'sb-customizer') }
                                    icon='tag'
                                    boxshadow={false}
                                    link={upsellContent?.buttons['lite']}
                                    target={upsellContent?.buttons['lite'] ? '_blank' : false}
                                />
                            }
                        </div>
                        {
                            upsellContent?.image &&
                            <div className='sb-upsell-modal-image sb-fs'>
                                <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/' + upsellContent?.image } alt={__( 'Upsell Modal Icon', 'sb-customizer' )} />
                            </div>
                        }
                    </div>
                    {
                        upsellContent?.includeContent === true &&
                        <div className='sb-upsell-modal-content sb-fs'>
                            <strong>{ __('And get much more!', 'sb-customizer') }</strong>
                            <div className='sb-upsell-modal-content-list sb-fs'>
                                {
                                    upsellContentList.map( (elm, key) => {
                                        return (
                                            <span className='sb-dark2-text sb-text-small ' key={key}>{ elm }</span>
                                        )
                                    } )
                                }
                            </div>
                        </div>
                    }
                    {
                        ( includedButtons.includes('upgrade') || includedButtons.includes('demo') ) &&
                        <div className='sb-upsell-modal-action-btns sb-fs'>
                            {
                                includedButtons.includes('upgrade') &&
                                <Button
                                    customClass='sb-upsell-act-btn'
                                    size='large'
                                    type='brand'
                                    text={ __('Upgrade', 'sb-customizer') }
                                    icon='logo-white'
                                    link={upsellContent?.buttons['upgrade']}
                                    target={upsellContent?.buttons['upgrade'] ? '_blank' : false}
                                />
                            }
                            {
                                includedButtons.includes('demo') &&
                                <Button
                                    customClass='sb-upsell-act-btn'
                                    size='large'
                                    type='secondary'
                                    text={ __('Video Demo', 'sb-customizer') }
                                    icon='eye'
                                    link={upsellContent?.buttons['demo']}
                                    target={upsellContent?.buttons['demo'] ? '_blank' : false}
                                />
                            }
                        </div>
                    }
                </div>

            </ModalContainer>
    )
}

export default UpsellPopupModal;
