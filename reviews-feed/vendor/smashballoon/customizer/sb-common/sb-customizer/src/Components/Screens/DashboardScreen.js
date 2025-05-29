import { useState, useMemo, useEffect } from 'react'
import SbUtils from '../../Utils/SbUtils'
import Header from '../Global/Header'
import { __ } from '@wordpress/i18n'
import FeedsList from './Dashboard/FeedsList'
import EmptyState from './Dashboard/EmptyState'
import CreationProcessScreen from './CreationProcessScreen'
import DashboardScreenContext from '../Context/DashboardScreenContext';
import Button from '../Common/Button';
import LicenseFlowScreen from './LicenseFlowScreen'
import ManualFacebookSource from '../Modals/ManualFacebookSource'


const DashboardScreen = (  { editorTopLoader, editorNotification, editorConfirmDialog, fbModal, globalSettings, allowedTiers, isPro, upsellModal, apis, apiLimits, noticeBar, noticeBarMemo, fbManualModal, bulkModal, bulkSr, freeRet, addSModal, freeRetModal} ) => {

    const sbCustomizer = window.sb_customizer;

    const checkPluginLicenseKey = SbUtils.checkNotEmpty( globalSettings.pluginSettings?.license_key )
                                 && SbUtils.checkNotEmpty( globalSettings.pluginSettings?.license_status )
                                 && globalSettings.pluginSettings?.license_status !== 'invalid'

    const [ dashScreen, setDashScreen ] = useState( checkPluginLicenseKey || !isPro ? 'welcome' : 'enterLicense' );
    const headingText = () => {
        switch (dashScreen) {
            case 'creationProcess':
                return __( 'Create a feed', 'sb-customizer' )
            case 'enterLicense':
                return __( 'Getting Started', 'sb-customizer' )

            default:
                return __( 'Dashboard', 'sb-customizer' )
        }
    }

    const [ feedsList, setFeedsList ] = useState( sbCustomizer.feedsList || []);
    const feeds = useMemo(
        () => ({ feedsList, setFeedsList }),
		[feedsList, setFeedsList]
    );

    const [ feedsCount, setFeedsCount ] = useState( sbCustomizer.feedsCount || 0);
    const feedsNumber = useMemo(
        () => ({ feedsCount, setFeedsCount }),
		[feedsCount, setFeedsCount]
    );


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

    const [ templates ] = useState( sbCustomizer.templatesList || [] );

    useEffect(() => {
        if (sbCustomizer?.openSourceModal) {
           setDashScreen( 'creationProcess' )
        }
    }, []);

    const connectFacebookActiveMemo = useMemo( () => {
        if( fbModal.connectFacebookActive === true || ( fbManualModal?.connectFacebookManualActive !== undefined && fbManualModal?.connectFacebookManualActive === true ) && isPro ){
            setDashScreen( 'creationProcess' )
            return true;
        }
        return false;
    },[ fbModal ]);

    const [ pluginNotices, setpluginNotices ] = useState( sbCustomizer.pluginNotices || [] );
    const globalNotices = useMemo(
        () => ({ pluginNotices, setpluginNotices  }),
		[ pluginNotices, setpluginNotices ]
    );


    return (
        <DashboardScreenContext.Provider
            value={{
                sbCustomizer,
                feeds,
                sources,
                templates,
                editorTopLoader,
                editorNotification,
                editorConfirmDialog,
                apis,
                noticeBarMemo,
                noticeBar,
                fbModal,
                globalSettings,
                setDashScreen,
                apiLimits,
                globalNotices,
                allowedTiers,
                isPro,
                upsellModal,
                feedsNumber,
                fbManualModal,
                sourcesCount,
                bulkModal,
                bulkSr,
                freeRet,
                addSModal,
                freeRetModal
            }}
        >
            <Header
                className='sb-dashboard-header'
                heading={ headingText() }
                editorTopLoader={editorTopLoader}
                showHelpButton={ checkPluginLicenseKey }
                topNoticeBar={noticeBar.topNoticeBar}
                setTopNoticeBar={ (e) => {
                    noticeBar.setTopNoticeBar(e)
                } }
            />
            {
                dashScreen === 'enterLicense' && isPro &&
                <LicenseFlowScreen
                />
            }
            {
                dashScreen === 'welcome' &&
                <section className='sb-full-wrapper sb-fs'>
                    {
                        sbCustomizer?.adminNoticeContent !== null &&
                        <section className='sb-fs'
                        dangerouslySetInnerHTML={{__html: sbCustomizer?.adminNoticeContent }}></section>
                    }
                    <div className='sb-dashboard-heading sb-fs'>
                        <h2 className='sb-h2'>{ __( 'All Feeds', 'sb-customizer' ) }</h2>
                        <div className='sb-dashboard-action-btns'>
                            <Button
                                customClass='sb-dashboard-btn'
                                type='primary'
                                size='small'
                                icon='plus'
                                iconSize='11'
                                text={ __( 'Add New', 'sb-customizer' ) }
                                onClick={ () => {
                                    setDashScreen( 'creationProcess' )
                                } }
                            />
                        </div>
                    </div>
                    {
                        feeds.feedsList.length <= 0 &&
                        <EmptyState/>
                    }
                    {
                        feeds.feedsList.length > 0 &&
                        <FeedsList/>
                    }
                </section>
            }
            {
                dashScreen === 'creationProcess' &&
                <section className='sb-full-wrapper sb-fs'>

                    <div className='sb-dashboard-heading sb-fs'>
                        <h1 className='sb-h1'>{ __( 'Create a Review feed', 'sb-customizer' ) }</h1>
                    </div>
                    <CreationProcessScreen
                        onBackClick={ () => {
                            setDashScreen( 'welcome' )
                        } }
                    />

                </section>
            }
            {
                ( fbManualModal?.connectFacebookManualActive !== undefined
                && fbManualModal?.connectFacebookManualActive === true && isPro ) &&
                <ManualFacebookSource/>
            }
        </DashboardScreenContext.Provider>
    )
}

export default DashboardScreen;