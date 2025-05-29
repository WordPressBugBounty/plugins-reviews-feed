import { __ } from '@wordpress/i18n';
import SbUtils from '../../../Utils/SbUtils'
import Button from '../../Common/Button';

const Header = ( { feedSettings, editorFeedData, isPro, globalSettings } ) => {
    let totalReviews = 0,
        ratingAverage = 0;

    editorFeedData?.feedData?.sourcesList?.filter( source => {
        const info = SbUtils.jsonParse(source.info);
        if( info ){
            totalReviews += info?.total_rating ? parseInt(info.total_rating) : 0;
            ratingAverage += info?.rating ? parseFloat(info.rating) : 0;
        }
    });
    ratingAverage = Math.round((ratingAverage / editorFeedData?.feedData?.sourcesList.length) * 10) / 10 ;
    const halfStarIcon = ( i ) => {
        const icons = [SbUtils.printIcon( 'star', 'sb-feed-header-icon sb-item-rating-icon-dimmed' , i ) , SbUtils.printIcon( 'star', ' sb-item-rating-icon-halfdimmed')];
        return (
            <span className='sb-feed-item-icon sb-feed-item-icon-half' key={i}>
                { icons }
            </span>
        )
    }
    return (
            feedSettings.showHeader &&
                <section className='sb-feed-header sb-fs' data-align='left'>
                    {
                        SbUtils.addHighlighter( 'header', 'Header', 0, true  )
                    }
                    <div className='sb-feed-header-content'>
                        {
                            feedSettings.headerContent.includes( 'heading' ) &&
                            <div className='sb-feed-header-heading'>
                                <span className='sb-relative sb-flex'>
                                    {feedSettings.headerHeadingContent}
                                    { SbUtils.addHighlighter( 'heading', 'Heading', 0 ,  ['id', 'heading'] ) }
                                </span>
                            </div>
                        }
                        <div className='sb-feed-header-bottom sb-fs'>
                            {
                                ( feedSettings.headerContent.includes( 'averagereview' ) && isPro ) &&
                                <div className='sb-feed-header-average sb-relative'>
                                    <span className='sb-feed-header-rating'>{ ratingAverage }</span>
                                    <span className='sb-feed-header-rating-icons'>
                                        {
                                            Array.from({ length: 5 } , (ic, i) => {
                                                const iconClass = ratingAverage - i < 1 ? ' sb-item-rating-icon-dimmed' : '';
                                                if( ratingAverage - i < 1 && ratingAverage - i >= 0.5 ){
                                                    return halfStarIcon( i )
                                                }
                                                return SbUtils.printIcon( 'star', 'sb-feed-header-icon' + iconClass, i )
                                            })
                                        }
                                    </span>
                                    <span className='sb-feed-header-rating-subtext'>Over { totalReviews } Reviews</span>
                                    { SbUtils.addHighlighter( 'header-average', 'Average Reviews Rating' ) }
                                </div>
                            }
                            {
                                feedSettings.headerContent.includes( 'button' ) &&
                                <div className='sb-feed-header-btn-ctn sb-relative'>
                                    <Button
                                        icon='pen'
                                        customClass='sb-feed-header-btn'
                                        text={globalSettings?.pluginSettings?.translations?.writeReview ?? __('Write a Review', 'sb-customizer')}
                                    />
                                    { SbUtils.addHighlighter( 'header-button', 'Header Button' ) }

                                </div>
                            }
                        </div>
                    </div>
                </section>
    )
}

export default Header;