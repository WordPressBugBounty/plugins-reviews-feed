import { useContext } from 'react'
import { __ } from '@wordpress/i18n';
import SbUtils from '../../Utils/SbUtils'
import FeedEditorContext from '../Context/FeedEditorContext'
import { default as FeedHeader } from '../ReviewsPlugin/Feed/Header'
import PostsWrapper  from '../ReviewsPlugin/Feed/PostsWrapper'
import LoadMoreButton  from '../ReviewsPlugin/Feed/LoadMoreButton'
import ModerationMode from '../ReviewsPlugin/Misc/ModerationMode';

const Preview = () => {

    const {
            sbCustomizer,
            editorFeedSettings,
            editorActiveDevice,
            editorFeedData,
            editorModerationMode,
            isPro,
            globalSettings
        } = useContext( FeedEditorContext ),

        feedSettings = editorFeedSettings.feedSettings,
        devices = [ 'mobile', 'tablet', 'desktop' ];

    const getPostsList = () => {
        let postsList = [...editorFeedData.feedData.posts];

        if( !isPro ){
            return postsList.splice( 0, feedSettings.numPostDesktop)
        }
        switch (editorActiveDevice.device) {
            case 'tablet':
                return postsList.splice( 0, feedSettings.numPostTablet)
            case 'mobile':
                return postsList.splice( 0, feedSettings.numPostMobile)
            default:
                return postsList.splice( 0, feedSettings.numPostDesktop)
        }

    }


    return (
        <section className='sb-customizer-preview' data-preview-device={editorActiveDevice.device}>
            <section className='sb-preview-wrapper sb-fs sb-tr-2'>
                {
                    editorModerationMode.moderationMode === true &&
                    <ModerationMode
                    />
                }
                {
                    editorModerationMode.moderationMode !== true &&
                    <>
                        <section className='sb-preview-devices-top sb-fs'>
                            <span className='sb-bold sb-text-tiny sb-dark2-text'>{ __('Preview', 'sb-customizer') }</span>
                            <div className='sb-preview-devices-chooser'>
                                {
                                    devices.reverse().map( device => {
                                        return <button
                                                    className='sb-preview-chooser-btn'
                                                    data-device={device}
                                                    key={device}
                                                    data-active={editorActiveDevice.device === device}
                                                    onClick={ () => {
                                                        editorActiveDevice.setDevice(device)
                                                    } }
                                                >
                                                    { SbUtils.printIcon( device ) }
                                                </button>
                                    })
                                }
                            </div>
                        </section>
                        <section
                            className='sb-feed-wrapper sb-fs'
                            id={"sb-reviews-container-" + editorFeedData?.feedData?.feed_info?.id}
                        >
                            <section
                                className='sb-feed-container sb-fs'
                                data-layout={feedSettings.layout}
                                data-post-style={feedSettings.postStyle}
                                >

                                <FeedHeader
                                    feedSettings={feedSettings}
                                    editorFeedData={editorFeedData}
                                    isPro={isPro}
                                    globalSettings={globalSettings}
                                />
                                <PostsWrapper
                                    feedSettings={feedSettings}
                                    posts={ getPostsList() }
                                    isPro={isPro}
                                />
                                {
                                    isPro &&
                                    <LoadMoreButton
                                        feedSettings={feedSettings}
                                    />
                                }

                            </section>
                        </section>
                    </>
                }
            </section>
        </section>
    );

}

export default Preview;