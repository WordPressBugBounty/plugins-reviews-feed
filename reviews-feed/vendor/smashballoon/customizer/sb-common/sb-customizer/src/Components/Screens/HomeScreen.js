import { useMemo, useState } from 'react';
import FeedEditor from './FeedEditor'
import DashboardScreen from './DashboardScreen'
import Notification from '../Global/Notification';
import ConfirmDialog from '../Global/ConfirmDialog';
import SettingsPage from './SettingsPage';
import AboutPage from './AboutPage';
import SupportPage from './SupportPage';
import UpsellPopupModal from '../Modals/UpsellPopupModal';
import SbUtils from '../../Utils/SbUtils';
import CollectionsScreen from './CollectionsScreen';
import ModalContainer from '../Global/ModalContainer';
import BulkHistoryModal from '../Modals/BulkHistoryModal';

const HomeScreen = () => {
    const sbCustomizer = window.sb_customizer;
    const [ loader, setLoader ] = useState( false );
    const [ isPro, setIsPro ] = useState( sbCustomizer?.isPro && sbCustomizer?.isPro ? true : false  );

    const [ bulkHistoryModalNotice, setBulkHistoryModalNotice ] = useState( false );
    const bulkModal = useMemo(
        () => ({ bulkHistoryModalNotice, setBulkHistoryModalNotice}),
		[ bulkHistoryModalNotice, setBulkHistoryModalNotice]
    );

    const [ bulkHistorySources, setBulkHistorySources ] = useState( sbCustomizer?.bulkHistorySources || [] );
    const bulkSr = useMemo(
        () => ({ bulkHistorySources, setBulkHistorySources }),
		[ bulkHistorySources, setBulkHistorySources ]
    );

    const editorTopLoader = useMemo(
        () => ({ loader, setLoader }),
		[ loader, setLoader ]
    );

    const [ notification, setNotification ] = useState({
        active : false
    });
    const editorNotification = useMemo(
        () => ({ notification, setNotification }),
		[ notification, setNotification ]
    );


    const [ confirmDialog, setConfirmDialog ] = useState({
        active : false
    });

    const editorConfirmDialog = useMemo(
        () => ({ confirmDialog, setConfirmDialog }),
		[ confirmDialog, setConfirmDialog ]
    );

    const [ addApiKeyModal, setAddApiKeyModal ] = useState( {
        active : false
    } );
    const apiKeyModal = useMemo(
        () => ({ addApiKeyModal, setAddApiKeyModal}),
		[addApiKeyModal, setAddApiKeyModal]
    );

    const [ tiers, setTiers ] = useState( sbCustomizer?.pluginStatus?.tier_allowed_providers ?? [ 'google', 'yelp' ]);
    const allowedTiers = useMemo(
        () => ({ tiers, setTiers }),
		[tiers, setTiers]
    );

    //Check for Facebook Sources Modal
    const [ connectFacebookActive , setConnectFacebookActive ] = useState( sbCustomizer?.newSourceData !== undefined );
    const fbModal = useMemo(
        () => ({ connectFacebookActive , setConnectFacebookActive }),
		[ connectFacebookActive , setConnectFacebookActive ]
    );


    //Check for Manual Facebook Sources Modal
    const [ connectFacebookManualActive , setConnectFacebookManualActive ] = useState( sbCustomizer?.manualSourcePopupInit !== undefined );
    const fbManualModal = useMemo(
        () => ({ connectFacebookManualActive , setConnectFacebookManualActive }),
		[ connectFacebookManualActive , setConnectFacebookManualActive ]
    );




    const [ pluginSettings , setPluginSettings ] = useState( sbCustomizer?.pluginSettings );

    const globalSettings = useMemo(
        () => ({ pluginSettings , setPluginSettings }),
		[ pluginSettings , setPluginSettings ]
    );


    const [ upsellActive , setUpsellActive ] = useState( false );
    const upsellModal = useMemo(
        () => ({ upsellActive , setUpsellActive }),
		[ upsellActive , setUpsellActive  ]
    );

    const [ feedData, setFeedData ] = useState( sbCustomizer?.feedData || []  );
    const editorFeedData = useMemo(
        () => ({ feedData, setFeedData }),
		[feedData, setFeedData]
    );

    const [ apiKeys, setApiKeys ] = useState( sbCustomizer.apiKeys || [] );
    const apis = useMemo(
        () => ({ apiKeys, setApiKeys }),
		[apiKeys, setApiKeys]
    );

    let defaultApiKeyLimist = () => {
        if(sbCustomizer.apiKeyLimits !== undefined){
            let newApiLimitsGoogle = [
                ...sbCustomizer.apiKeyLimits
            ]
            if( apis.apiKeys['google'] === undefined ){
                newApiLimitsGoogle.push('google')
            }
            return newApiLimitsGoogle;
        }
        return sbCustomizer.apiKeyLimits;
    }

    const [ apiKeyLimits, setApiKeyLimits  ] = useState( defaultApiKeyLimist() || [] );
    const apiLimits = useMemo(
        () => ({ apiKeyLimits, setApiKeyLimits }),
		[apiKeyLimits, setApiKeyLimits]
    );


    const [ topNoticeBar, setTopNoticeBar ] = useState({});
    const noticeBar = useMemo(
        () => ({ topNoticeBar, setTopNoticeBar  }),
		[ topNoticeBar, setTopNoticeBar ]
    );

    //Free Retriever
    const [ freeRetrieverData, setFreeRetrieverData ] = useState( sbCustomizer?.freeRetrieverData || [] );
    const freeRet = useMemo(
        () => ({ freeRetrieverData, setFreeRetrieverData }),
		[ freeRetrieverData, setFreeRetrieverData ]
    );

    const [ modalType, setModalType ] = useState('addSource'); // addSource, freeRetriever
    const addSModal = useMemo(
        () => ({ modalType, setModalType }),
		[ modalType, setModalType ]
    );

    const [ retModalType, setRetModalType ] = useState(''); // verifyEmail - sourceAdded - limitExceeded - addApiKey
     const freeRetModal = useMemo(
        () => ({ retModalType, setRetModalType }),
		[ retModalType, setRetModalType ]
    );


    const noticeBarMemo = useMemo(
        () => SbUtils.checkAPIKeys( noticeBar, topNoticeBar, apis, feedData, apiKeyModal, apiLimits, isPro, freeRet ),
        [ noticeBar, topNoticeBar, apis, feedData, apiKeyModal ]
    );

    //const [ topNoticeBarActive, setTopNoticeBarActive ] = useState(false);
    const topNoticeBarActive = useMemo( () => {
        let isActive = false
        if( noticeBarMemo !== undefined ){
            Object.keys(noticeBarMemo.topNoticeBar)?.forEach( ( nbr, nbKey ) => {
               nbr = noticeBarMemo.topNoticeBar[nbr];
               if( nbr.active === true )
                   isActive = true;
            });
        }

        return isActive;
    },[topNoticeBar, apiKeys]);

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



    return (
        <>
            { //DashBoard Screen
                (!sbCustomizer.isFeedEditor && sbCustomizer.reactScreen === 'customizer') &&
                <DashboardScreen
                    editorTopLoader={ editorTopLoader }
                    editorNotification={ editorNotification }
                    editorConfirmDialog={ editorConfirmDialog }
                    apiKeyModal={ apiKeyModal }
                    fbModal={ fbModal }
                    globalSettings={ globalSettings }
                    allowedTiers={ allowedTiers }
                    isPro={ isPro }
                    upsellModal={ upsellModal }

                    editorFeedData={editorFeedData}
                    apis={apis}
                    apiLimits={apiLimits}
                    noticeBar={noticeBar}
                    noticeBarMemo={noticeBarMemo}
                    topNoticeBarActive={topNoticeBarActive}
                    fbManualModal={fbManualModal}
                    sourcesCount={sourcesCount}
                    bulkModal={bulkModal}
                    bulkSr={bulkSr}
                    freeRet={freeRet}
                    addSModal={addSModal}
                    freeRetModal={freeRetModal}
                />
            }

            { //Feed Editor
               ( sbCustomizer.isFeedEditor && sbCustomizer.reactScreen === 'customizer')  &&
                <FeedEditor
                    editorTopLoader={ editorTopLoader }
                    editorNotification={ editorNotification }
                    editorConfirmDialog={ editorConfirmDialog }
                    apiKeyModal={ apiKeyModal }
                    fbModal={ fbModal }
                    allowedTiers={ allowedTiers }
                    isPro={ isPro }
                    upsellModal={ upsellModal }
                    editorFeedData={editorFeedData}
                    apis={apis}
                    apiLimits={apiLimits}
                    noticeBar={noticeBar}
                    noticeBarMemo={noticeBarMemo}
                    topNoticeBarActive={topNoticeBarActive}
                    fbManualModal={fbManualModal}
                    globalSettings={ globalSettings }
                    sourcesCount={sourcesCount}
                    bulkModal={bulkModal}
                    bulkSr={bulkSr}
                    freeRet={freeRet}
                    addSModal={addSModal}
                    freeRetModal={freeRetModal}
                />
            }

            { //Settings Page
               sbCustomizer.reactScreen === 'settings' &&
                <SettingsPage
                    editorTopLoader={ editorTopLoader }
                    editorNotification={ editorNotification }
                    editorConfirmDialog={ editorConfirmDialog }
                    apiKeyModal={ apiKeyModal }
                    fbModal={ fbModal }
                    allowedTiers={ allowedTiers }
                    isPro={ isPro }
                    upsellModal={ upsellModal }

                    editorFeedData={editorFeedData}
                    apis={apis}
                    apiLimits={apiLimits}
                    noticeBar={noticeBar}
                    noticeBarMemo={noticeBarMemo}
                    topNoticeBarActive={topNoticeBarActive}
                    fbManualModal={fbManualModal}
                    sourcesCount={sourcesCount}
                    bulkModal={bulkModal}
                    bulkSr={bulkSr}
                    freeRet={freeRet}
                    addSModal={addSModal}
                    freeRetModal={freeRetModal}
                />
            }

            { //About Us Page
               sbCustomizer.reactScreen === 'aboutus' &&
                <AboutPage
                    sbCustomizer={ sbCustomizer }
                    editorTopLoader={ editorTopLoader }
                    editorNotification={ editorNotification }
                    editorConfirmDialog={ editorConfirmDialog }
                    isPro={ isPro }
                    upsellModal={ upsellModal }

                    editorFeedData={editorFeedData}
                    apis={apis}
                    apiLimits={apiLimits}
                    noticeBar={noticeBar}
                    noticeBarMemo={noticeBarMemo}
                    topNoticeBarActive={topNoticeBarActive}
                    sourcesCount={sourcesCount}
                    addSModal={addSModal}
                />
            }

            { //Support Page
               sbCustomizer.reactScreen === 'support' &&
                <SupportPage
                    sbCustomizer={ sbCustomizer }
                    editorTopLoader={ editorTopLoader }
                    editorNotification={ editorNotification }
                    editorConfirmDialog={ editorConfirmDialog }
                    isPro={ isPro }
                    upsellModal={ upsellModal }
                    editorFeedData={editorFeedData}
                    apis={apis}
                    apiLimits={apiLimits}
                    noticeBar={noticeBar}
                    noticeBarMemo={noticeBarMemo}
                    topNoticeBarActive={topNoticeBarActive}
                    sourcesCount={sourcesCount}
                    addSModal={addSModal}
                />
            }
            { //Collections Screen
                sbCustomizer.reactScreen === 'collections' &&
                <CollectionsScreen
                    sbCustomizer={ sbCustomizer }
                    editorTopLoader={ editorTopLoader }
                    editorNotification={ editorNotification }
                    editorConfirmDialog={ editorConfirmDialog }
                    isPro={ isPro }
                    upsellModal={ upsellModal }
                    editorFeedData={editorFeedData}
                    apis={apis}
                    apiLimits={apiLimits}
                    noticeBar={noticeBar}
                    noticeBarMemo={noticeBarMemo}
                    topNoticeBarActive={topNoticeBarActive}
                    sources={sources}
                    allowedTiers={allowedTiers}
                    sourcesCount={sourcesCount}
                    addSModal={addSModal}
                />
            }

            <Notification
                active={ editorNotification.notification?.active }
                icon={ editorNotification.notification?.icon }
                text={ editorNotification.notification?.text }
                type={ editorNotification.notification?.type }
            />
            <ConfirmDialog
                active={ editorConfirmDialog.confirmDialog?.active }
                heading={ editorConfirmDialog.confirmDialog?.heading }
                description={ editorConfirmDialog.confirmDialog?.description }
                confirm={ editorConfirmDialog.confirmDialog?.confirm }
                cancel= { editorConfirmDialog.confirmDialog?.cancel }
                onClose = { () => {
                    editorConfirmDialog.setConfirmDialog({
                        active : false
                    });
                } }
            />

            <UpsellPopupModal
                upsellModal={upsellModal}
                upsellContent={sbCustomizer?.upsellContent}
            />
        </>
    )
}

export default HomeScreen;