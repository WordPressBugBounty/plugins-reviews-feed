import { useContext, useEffect, useState } from 'react'
import OwlCarousel from 'react-owl-carousel';
import 'owl.carousel/dist/assets/owl.carousel.css'
import 'owl.carousel/dist/assets/owl.theme.default.css'
import SinglePost from './SinglePost'
import FeedEditorContext from '../../Context/FeedEditorContext'

const CarouselLayout = ( { feedSettings, posts, isPro } ) => {

    const { editorActiveDevice, sbCustomizer, editorFeedSettings } = useContext( FeedEditorContext );

    let desktopNumber = feedSettings.carouselDesktopColumns;
    if( sbCustomizer.isFeedEditor ){
        switch ( editorActiveDevice.device ) {
            case 'mobile':
                desktopNumber = feedSettings.carouselMobileColumns;
                break;
            case 'tablet':
                desktopNumber = feedSettings.carouselTabletColumns;
                break;
            default :
                desktopNumber = feedSettings.carouselDesktopColumns;
                break;
        }
    }

    let responsiveLayoutData =  {
        480 : {
            items: feedSettings.carouselMobileColumns
            // rows: feedSettings.carouselMobileRows
        },
        600 : {
            items: feedSettings.carouselTabletColumns
          // rows: feedSettings.carouselTabletRows
        },
        1024 : {
            items: desktopNumber
            //rows: feedSettings.carouselDesktopRows
        }
    };

    const [rowsNumber, setRowNumber] = useState(editorFeedSettings.feedSettings.carouselDesktopRows)
    useEffect(() => {
        setRowNumber(editorFeedSettings.feedSettings.carouselDesktopRows)
    }, [editorFeedSettings.feedSettings]);

    const printCarouselItems = (number) => {
        return (
            posts.map( (post, postIndex) => {
                if (number == 2) {
                    const nextIndex = postIndex + 1;
                    const shouldShowNext = posts[nextIndex] !== undefined;
                    return (
                        <div key={postIndex}>
                            <SinglePost
                                post={post}
                                postIndex={postIndex}
                                feedSettings={feedSettings}
                                key={postIndex}
                                isPro={isPro}
                            />
                            {
                                shouldShowNext &&
                                <SinglePost
                                    post={ posts[nextIndex]}
                                    postIndex={nextIndex}
                                    feedSettings={feedSettings}
                                    key={nextIndex}
                                    isPro={isPro}
                                />
                            }
                        </div>
                    )
                } else {
                    return (
                        <SinglePost
                            post={post}
                            postIndex={postIndex}
                            feedSettings={feedSettings}
                            key={postIndex}
                            isPro={isPro}
                        />
                    )
                }
            })
        )
    }


    return (
        <OwlCarousel
            responsive={ responsiveLayoutData }
            margin={ parseInt( feedSettings.horizontalSpacing ) }
            loop={ feedSettings.carouselLoopType === 'infinity' }
            rewind={ feedSettings.carouselLoopType === 'rewind' }
            nav={ feedSettings.carouselShowArrows }
            dots={ feedSettings.carouselShowPagination }
            autoplay={ feedSettings.carouselEnableAutoplay }
            autoplayTimeout={ feedSettings.carouselIntervalTime }
        >
            {
                printCarouselItems(editorFeedSettings.feedSettings.carouselDesktopRows)
            }
        </OwlCarousel>
    )
}

export default CarouselLayout;