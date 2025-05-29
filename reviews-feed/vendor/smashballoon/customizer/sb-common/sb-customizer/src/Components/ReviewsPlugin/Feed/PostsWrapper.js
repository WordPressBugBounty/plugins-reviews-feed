import { __ } from '@wordpress/i18n';
import SbUtils from '../../../Utils/SbUtils'
import ListLayout from './ListLayout'
import GridLayout from './GridLayout'
import MasonryLayout from './MasonryLayout'
import CarouselLayout from './CarouselLayout'

const PostsWrapper = ( { feedSettings, posts, isPro } ) => {
    const postsLoopOutput = () => {
        switch ( feedSettings.layout ) {
            case 'grid':
                return (
                    <GridLayout
                        posts={ posts }
                        feedSettings={ feedSettings }
                        isPro={ isPro }
                    />
                )
            case 'masonry':
                return (
                    <MasonryLayout
                        posts={ posts }
                        feedSettings={ feedSettings }
                        isPro={ isPro }
                    />
                )
            case 'carousel':
                return (
                    <CarouselLayout
                        posts={ posts }
                        feedSettings={ feedSettings }
                        isPro={ isPro }
                    />
                )
            default:
                return (
                    <ListLayout
                        posts={ posts }
                        feedSettings={ feedSettings }
                        isPro={ isPro }
                    />
                )

        }
    }

    return (
        <section
            className='sb-feed-posts sb-fs'
            data-icon-size={feedSettings?.ratingIconSize}
            data-avatar-size='medium'
        >
        { postsLoopOutput() }
        { SbUtils.addHighlighter( 'posts-layout', 'Posts Layout', 0, true ) }
        </section>
    )
}
export default PostsWrapper;