import { __ } from "@wordpress/i18n"
import { useContext } from "react";
import SbUtils from "../../Utils/SbUtils";
import FeedEditorContext from "../Context/FeedEditorContext";

const SidebarCards = ( currentCardIndex ) => {
    const {
        isPro,
        upsellCards,
        upsellModal
    } = useContext( FeedEditorContext );
    const cIndex = currentCardIndex.currentCardIndex;

    if( isPro === false
        && upsellCards?.sbCards !== undefined
        && upsellCards?.sbCards.length > 0
        && upsellCards?.sbCards[cIndex] !== undefined
    ){
        return (
            <div
                className='sb-sidebar-card sb-fs'
                onClick={ () => {
                    if(  upsellCards?.sbCards[cIndex]?.modal !== undefined ){
                        SbUtils.openUpsellModal( upsellCards?.sbCards[cIndex]?.modal, upsellModal )
                    }
                    else if(  upsellCards?.sbCards[cIndex]?.link !== undefined){
                        window.open( upsellCards?.sbCards[cIndex]?.link, '_blank' )
                    }
                } }
            >
                <div className='sb-sidebar-card-content'>
                    <div className='sb-sidebar-card-heading sb-fs'>
                        <strong className='sb-text-small sb-dark-text'>{upsellCards?.sbCards[cIndex].heading}</strong>
                        <span className='sb-sidebar-card-pro-label'>PRO</span>
                    </div>
                    <div className='sb-sidebar-card-description sb-light-text2 sb-text-tiny sb-fs'>
                        {upsellCards?.sbCards[cIndex].description}
                    </div>
                    <div className='sb-sidebar-card-link'>
                        <strong>{ __('Learn More', 'sb-customizer') }</strong>
                    </div>
                </div>
                <div className='sb-sidebar-card-image'>
                    <img src={window.sb_customizer.assetsURL + 'sb-customizer/assets/images/' +  upsellCards?.sbCards[cIndex].image} alt={upsellCards?.sbCards[cIndex].heading} />
                </div>
            </div>
        )
    }
    return null;
}

export default SidebarCards;