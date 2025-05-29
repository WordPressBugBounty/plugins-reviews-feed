import { __ } from '@wordpress/i18n';
import SbUtils from '../../../Utils/SbUtils'

const PostRating = ( { post, postIndex, feedSettings } ) => {
    const halfStarIcon = ( i ) => {
        const icons = [SbUtils.printIcon( 'star', 'sb-item-rating-icon sb-item-rating-icon-dimmed' , i ) , SbUtils.printIcon( 'star', ' sb-item-rating-icon-halfdimmed')];
        return (
            <span className='sb-item-rating-icon sb-feed-item-icon-half' key={i}>
                { icons }
            </span>
        )
    }

    const isCollectionPost = (post) => {
        return post?.provider_id !== undefined && post?.provider_id.includes('collection')
    }


    return (
        <div className='sb-item-rating sb-fs'>
            <span className='sb-relative'>
                <div className='sb-item-rating-ctn'>
                    {
                        Array.from({ length: 5} , (ic, i) => {
                            const iconClass = post.rating - i < 1 ? ' sb-item-rating-icon-dimmed' : '';
                            if( post.rating - i === 0.5 ){
                                return halfStarIcon( i )
                            }
                            return SbUtils.printIcon( 'star', 'sb-item-rating-icon' + iconClass, i )
                        })
                    }
                    {
                        post?.provider?.name === 'facebook' && !isCollectionPost(post) &&
                        <span className='sb-facbook-rating-notice'>
                            {
                                SbUtils.printTooltip(
                                    __( 'Facebook reviews are only based upon a positive or negative recommendation','sb-customizer')
                                    , {
                                            type : 'white',
                                            textAlign : 'center',
                                            replaceText : false
                                        } )
                            }
                            {
                                SbUtils.printIcon( 'info', 'sb-facbook-rating-notice-icon' )
                            }
                        </span>
                    }
                </div>
                { SbUtils.addHighlighter( 'post-rating-icon', 'Rating' , postIndex ) }
            </span>
        </div>
    )
}

export default PostRating;