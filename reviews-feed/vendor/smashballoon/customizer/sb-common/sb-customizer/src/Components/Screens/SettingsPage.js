import { __ } from "@wordpress/i18n"
import { useMemo, useState } from "react";
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";
import SettingsScreenContext from "../Context/SettingsScreenContext";
import BottomUpsellBanner from "../Global/BottomUpsellBanner";
import Header from "../Global/Header";
import ManualFacebookSource from "../Modals/ManualFacebookSource";
import SettingSectionOutput from "./Settings/SettingSectionOutput";

const SettingsPage = ( { editorTopLoader, editorNotification, editorConfirmDialog, apiKeyModal, fbModal, allowedTiers, isPro, upsellModal, apis, apiLimits,  noticeBar, noticeBarMemo, fbManualModal, bulkModal, bulkSr, freeRet, addSModal, freeRetModal } ) => {
    const sbSettings = window.sb_customizer;
    const sbCustomizer = window.sb_customizer;
    sbSettings.settingsData = Object.values(sbSettings.settingsData);

    const [ pluginSettings, setPluginSettings ] = useState( sbSettings.pluginSettings );

    const settingsPage = useMemo(
        () => ({ pluginSettings, setPluginSettings }),
		[pluginSettings, setPluginSettings]
    );


    const [ sourcesList, setSourcesList ] = useState( sbSettings.sourcesList || [] );
    const sources = useMemo(
        () => ({ sourcesList, setSourcesList }),
		[sourcesList, setSourcesList]
    );

    const [ sourcesNumber, setSourcesNumber ] = useState( sbSettings.sourcesCount || 0 );
    const sourcesCount = useMemo(
        () => ({ sourcesNumber, setSourcesNumber  }),
		[sourcesNumber, setSourcesNumber]
    );

    const [ currentTab, setCurrentTab ] = useState( SbUtils.findElementById( sbSettings.settingsData, 'id', sbSettings.currentTab) );
    const tab = useMemo(
        () => ({ currentTab, setCurrentTab }),
		[currentTab, setCurrentTab]
    );

    const savePluginSettings = () => {
        const formData = {
            action : 'sbr_update_settings',
            settings : JSON.stringify( settingsPage.pluginSettings )
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __('Settings saved succesfully', 'sb-customizer' )
            }
        }
        SbUtils.ajaxPost(
            sbSettings.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function

            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )

    }

    const [ pluginNotices, setPluginNotices ] = useState( sbCustomizer.pluginNotices || [] );
    const globalNotices = useMemo(
        () => ({ pluginNotices, setPluginNotices  }),
		[ pluginNotices, setPluginNotices ]
    );

    return (
        <SettingsScreenContext.Provider
            value={{
                sbCustomizer,
                sbSettings,
                sources,
                editorTopLoader,
                editorNotification,
                editorConfirmDialog,
                apiKeyModal,
                apis,
                noticeBarMemo,
                noticeBar,
                settingsPage,
                fbModal,
                apiLimits,
                globalNotices,
                allowedTiers,
                isPro,
                upsellModal,
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
                heading={ __( 'Settings', 'sb-customizer' )}
                editorTopLoader={ editorTopLoader }
                showHelpButton={ true }
                topNoticeBar={noticeBar.topNoticeBar}
                setTopNoticeBar={ (e) => {
                    noticeBar.setTopNoticeBar(e)
                } }
            />

            <section className='sb-full-wrapper sb-fs sb-fs'>
                {
                    sbCustomizer?.adminNoticeContent !== null &&
                    <section className='sb-fs'
                    dangerouslySetInnerHTML={{__html: sbCustomizer?.adminNoticeContent }}></section>
                }
                <div className='sb-dashboard-heading sb-fs'>
                    <h2 className='sb-h2'>{ __( 'Settings', 'sb-customizer' ) }</h2>
                </div>

                <div className='sb-settings-ctn sb-fs'>
                    <div className='sb-settings-tabs-ctn sb-fs'>
                        <div className='sb-settings-tabs'>
                            {
                                sbSettings.settingsData.map( sTab => {
                                    return (
                                        <div
                                            className='sb-settings-tab sb-text sb-bold'
                                            key={ sTab.id }
                                            data-active={ sTab.id === tab.currentTab.id  }
                                            onClick={ () => {
                                                setCurrentTab(sTab)
                                            } }
                                        >
                                            { sTab.name }
                                        </div>
                                    )
                                } )
                            }
                        </div>
                        <div className='sb-settings-save-ctn'>
                            <Button
                                type='primary'
                                size='medium'
                                icon='success'
                                text={ __( 'Save Changes', 'sb-customizer' ) }
                                onClick={ () => {
                                    savePluginSettings()
                                } }
                            />
                        </div>
                    </div>

                    <div className='sb-settings-sections-ctn sb-fs'>
                        {
                            tab.currentTab &&
                            Object.keys( tab.currentTab?.sections ).map( ( secInd ) => {
                                const section = tab.currentTab?.sections[secInd];
                                return (
                                    ( ( section?.isProSetting === undefined && section?.isFreeSetting === undefined ) || ( section.isProSetting && isPro ) || ( section.isFreeSetting && !isPro ) ) &&
                                    <div
                                        className='sb-settings-section-ctn sb-fs'
                                        key={ secInd }
                                        data-bottom={ section.separator === true }
                                        data-type={ section.type }
                                        data-layout={ section.layout }
                                    >
                                        <div className='sb-settings-section-label'>
                                            { section.heading && <h4 className='sb-h4 sb-fs'>{ section.heading }</h4> }
                                            { section.description && <span className='sb-small-p sb-fs'>{ section.description }</span> }
                                        </div>
                                        <div className='sb-settings-section-content'>
                                            <div className='sb-settings-section-input sb-fs'>
                                                { section.inputDescription && <span className='sb-small-p sb-fs'>{ section.inputDescription }</span> }
                                                {
                                                    <SettingSectionOutput
                                                        section={ section }
                                                    />
                                                }
                                                { section.info && <span className='sb-text-small sb-dark2-text sb-fs' dangerouslySetInnerHTML={{__html: section.info }}></span> }
                                            </div>
                                        </div>
                                    </div>
                                )
                            } )
                        }
                    </div>

                    <div className='sb-fs'>
                        <div className='sb-settings-save-ctn'>
                            <Button
                                type='primary'
                                size='medium'
                                icon='success'
                                text={ __( 'Save Changes', 'sb-customizer' ) }
                                onClick={ () => {
                                    savePluginSettings()
                                } }
                            />
                        </div>
                    </div>
                    <BottomUpsellBanner
                        hidePro={false}
                    />
                </div>
            </section>
            {
                ( fbManualModal?.connectFacebookManualActive !== undefined
                && fbManualModal?.connectFacebookManualActive === true && isPro ) &&
                <ManualFacebookSource/>
            }
        </SettingsScreenContext.Provider>
    )
}

export default SettingsPage;