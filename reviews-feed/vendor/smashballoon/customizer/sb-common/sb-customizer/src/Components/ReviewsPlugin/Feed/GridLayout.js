import { useContext } from 'react'
import SinglePost from './SinglePost'
import FeedEditorContext from '../../Context/FeedEditorContext'


const GridLayout = ( { feedSettings, posts, isPro } ) => {

    const { editorActiveDevice, sbCustomizer } = useContext( FeedEditorContext );

    let desktopNumber = feedSettings.gridDesktopColumns;
    if( sbCustomizer.isFeedEditor ){
        switch ( editorActiveDevice.device ) {
            case 'mobile':
                desktopNumber = feedSettings.gridMobileColumns;
                break;
            case 'tablet':
                desktopNumber = feedSettings.gridTabletColumns;
                break;
            default :
                desktopNumber = feedSettings.gridDesktopColumns;
                break;
        }
    }


    return (
        <div
            className='sb-grid-wrapper'
            data-grid-columns={ desktopNumber }
            style={{ columnGap : `${feedSettings.horizontalSpacing}px`}}
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
        </div>
    )
}

export default GridLayout;