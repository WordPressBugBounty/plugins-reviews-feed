import { useContext } from 'react'
import Masonry, {ResponsiveMasonry} from "react-responsive-masonry"
import SinglePost from './SinglePost'
import FeedEditorContext from '../../Context/FeedEditorContext'


const MasonryLayout = ( { feedSettings, posts, isPro } ) => {

    const { editorActiveDevice, sbCustomizer } = useContext( FeedEditorContext );

    let desktopNumber = feedSettings.masonryDesktopColumns;

    if( sbCustomizer.isFeedEditor ){
        switch ( editorActiveDevice.device ) {
            case 'mobile':
                desktopNumber = parseInt(feedSettings.masonryMobileColumns);
                break;
            case 'tablet':
                desktopNumber = parseInt(feedSettings.masonryTabletColumns);
                break;
            default :
                desktopNumber = parseInt(feedSettings.masonryDesktopColumns);
                break;
        }
    }

    const masonryBreakPoint =  {
        350 : parseInt(feedSettings.masonryMobileColumns),
        750 : parseInt(feedSettings.masonryTabletColumns),
        900 : desktopNumber
    };

    return (
        <ResponsiveMasonry
            columnsCountBreakPoints={ masonryBreakPoint }
        >
            <Masonry
                gutter={`${feedSettings.horizontalSpacing}px`}
            >
                {
                    posts.map( (post, postIndex) =>
                        <SinglePost
                            post={post}
                            postIndex={postIndex}
                            feedSettings={feedSettings}
                            key={postIndex}
                            isPro={isPro}
                        />
                    )
                }
            </Masonry>
        </ResponsiveMasonry>
    )
}

export default MasonryLayout;