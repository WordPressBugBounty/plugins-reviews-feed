import { __ } from '@wordpress/i18n'
import { useState, useMemo, useRef, useEffect } from 'react'
import SbUtils from '../../Utils/SbUtils'
import FeedEditorContext from '../Context/FeedEditorContext'
import Preview from '../FeedEditor/Preview'
import Sidebar from '../FeedEditor/Sidebar'
import Header from '../Global/Header'
import TopNoticeBar from '../Global/TopNoticeBar'
import ManualFacebookSource from '../Modals/ManualFacebookSource'

const FeedEditor = ( { editorTopLoader, editorNotification, editorConfirmDialog, apiKeyModal, fbModal, allowedTiers, isPro, upsellModal, editorFeedData, apis, apiLimits, noticeBar, noticeBarMemo, topNoticeBarActive, fbManualModal, globalSettings, bulkModal, bulkSr, freeRet, addSModal, freeRetModal} ) => {
    const sbCustomizer = window.sb_customizer;
    const [ activeTab, setActiveTab ] = useState( SbUtils.findElementById( sbCustomizer.customizerData, 'id', sbCustomizer.feedEditor.defaultTab ) );
    const editorActiveTab = useMemo(
        () => ({ activeTab, setActiveTab }),
		[activeTab, setActiveTab]
    );

    const [ activeSection, setActiveSection ] = useState( null );
    const editorActiveSection = useMemo(
        () => ({ activeSection, setActiveSection }),
		[activeSection, setActiveSection]
    );

    editorActiveSection.setActiveSection = ( section ) => {
        setActiveSection( section )
        window.highlightedSection = section?.highlight !== undefined ? section.highlight : null;
    }

    const [ hoveredHighlightedSection, setHoveredHighlightedSection ] = useState( null );
    const editorHighlightedSection = useMemo(
        () => ({ hoveredHighlightedSection, setHoveredHighlightedSection }),
		[hoveredHighlightedSection, setHoveredHighlightedSection]
    );

    const [ feedDataInitial, setFeedDataInitial ] = useState( sbCustomizer.feedData ); //

    const [ feedSettings, setFeedSettings ] = useState( sbCustomizer.feedData.settings );
    const editorFeedSettings = useMemo(
        () => ({ feedSettings, setFeedSettings }),
		[feedSettings, setFeedSettings]
    );

    const editorFeedStyling = useMemo( () => {
        return SbUtils.getFeedStyling( feedSettings, sbCustomizer.customizerData, editorFeedData?.feedData?.feed_info?.id ) ;
    },  [ feedSettings, sbCustomizer.customizerData] );


    const [ breadCrumb, setBreadCrumb ] = useState( null );
    const editorBreadCrumb = useMemo(
        () => ({ breadCrumb, setBreadCrumb }),
		[breadCrumb, setBreadCrumb]
    );

    const [ moderationMode, setModerationMode ] = useState( false );
    const editorModerationMode = useMemo(
        () => ({ moderationMode, setModerationMode }),
		[moderationMode, setModerationMode]
    );

    const [ moderationModeReviews, setModerationModeReviews ] = useState( [] );
    const editorModerationReviews = useMemo(
        () => ({ moderationModeReviews, setModerationModeReviews }),
		[moderationModeReviews, setModerationModeReviews]
    );

    const [ moderationCurrentListSelected, setModerationCurrentListSelected ] = useState( [] );
    const editorModerationCurrentList = useMemo(
        () => ({ moderationCurrentListSelected, setModerationCurrentListSelected }),
		[moderationCurrentListSelected, setModerationCurrentListSelected]
    );


    const [ device, setDevice ] = useState( 'desktop' );
    const editorActiveDevice = useMemo(
        () => ({ device, setDevice }),
		[device, setDevice]
    );

    const [ activeViews, setActiveViews ] = useState( { } );
    const editorActiveViews = useMemo(
        () => ({ activeViews, setActiveViews }),
		[activeViews, setActiveViews]
    );

    const editorFeedHighlightedSection = useMemo( () => {
        return SbUtils.getHighlightedSection( sbCustomizer.customizerData )
    },  [ sbCustomizer.customizerData ] );



    const [ sourcesList, setSourcesList ] = useState( sbCustomizer.sourcesList || [] );
    const sources = useMemo(
        () => ({ sourcesList, setSourcesList }),
		[sourcesList, setSourcesList]
    );

    const [ sourcesNumber, setSourcesNumber ] = useState( sbCustomizer.sourcesCount || 0 );
    const sourcesCount = useMemo(
        () => ({ sourcesNumber, setSourcesNumber  }),
		[sourcesNumber, setSourcesNumber]
    );

    const connectFacebookActiveMemo = useMemo( () => {
        if( fbModal.connectFacebookActive === true || ( fbManualModal?.connectFacebookManualActive !== undefined && fbManualModal?.connectFacebookManualActive === true ) && isPro ){
            const settingsTab = SbUtils.findElementById( sbCustomizer.customizerData, 'id', 'sb-settings-tab' );
            editorActiveTab.setActiveTab( settingsTab  );
            setTimeout(() => {
                editorActiveSection.setActiveSection( settingsTab?.sections?.sources_section || {} )
            }, 100);
            return true;
        }
        return false;
    },[ fbModal ]);

    const [ headerCustomClasses, setHeaderCustomClasses ] = useState([]);
    const headerClasses = useMemo(
        () => ({ headerCustomClasses, setHeaderCustomClasses  }),
		[ headerCustomClasses, setHeaderCustomClasses ]
    );


    const [ tempControlPopup, setTempControlPopup ] = useState( null );
    const editorControl = useMemo(
        () => ({ tempControlPopup, setTempControlPopup  }),
		[ tempControlPopup, setTempControlPopup ]
    );


    const checkIsNewFeed = useMemo( () => {
        if( SbUtils.checkNotEmpty( localStorage.getItem('newCreatedFeed') ) ){
            SbUtils.saveFeedData( editorFeedData, editorFeedStyling, editorFeedSettings, sbCustomizer, editorTopLoader, editorNotification,  false );
            return true;
        }
        return false;
    },[]);
    const [ sbCards, setSbCards ] = useState( sbCustomizer.upsellSidebarCards );
    const upsellCards = useMemo(
        () => ({ sbCards, setSbCards }),
		[ sbCards, setSbCards ]
    );
    const [ currentCardIndex, setCurrentCardIndex ] = useState(Math.floor(Math.random() * sbCustomizer?.upsellSidebarCards?.length ))


    return (
        <FeedEditorContext.Provider
            value={{
                editorActiveTab,
                editorActiveSection,
                sbCustomizer,
                editorFeedSettings,
                editorBreadCrumb,
                editorActiveDevice,
                editorActiveViews,
                feedDataInitial,
                editorFeedData,
                editorFeedHighlightedSection,
                editorHighlightedSection,
                editorTopLoader,
                editorNotification,
                sources,
                apiKeyModal,
                apis,
                noticeBarMemo,
                noticeBar,
                editorConfirmDialog,
                fbModal,
                editorFeedStyling,
                editorModerationMode,
                editorModerationReviews,
                editorModerationCurrentList,
                headerClasses,
                editorControl,
                apiLimits,
                allowedTiers,
                isPro,
                upsellModal,
                upsellCards,
                currentCardIndex,
                setCurrentCardIndex,
                fbManualModal,
                globalSettings,
                sourcesCount,
                bulkModal,
                bulkSr,
                freeRet,
                addSModal,
                freeRetModal
            }}
        >
            <Header
                className='sb-customizer-header'
                type='customizer'
                editorTopLoader={editorTopLoader}
                topNoticeBar={noticeBar.topNoticeBar}
                setTopNoticeBar={ (e) => {
                    noticeBar.setTopNoticeBar(e)
                } }
            />
            <section
                className='sb-cutomizer-ctn sb-fs'
                data-noticebar={topNoticeBarActive}
            >
                <Sidebar />
                <Preview />
                <style data-style="sb-styling">
                    { editorFeedStyling }
                </style>
            </section>
            {
                ( fbManualModal?.connectFacebookManualActive !== undefined
                && fbManualModal?.connectFacebookManualActive === true && isPro ) &&
                <ManualFacebookSource/>
            }
        </FeedEditorContext.Provider>
    );

}

export default FeedEditor;