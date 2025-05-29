import { useContext } from 'react'
import SbUtils from '../../Utils/SbUtils'
import AddApiKeyModal from '../Modals/AddApiKeyModal'
import ModalContainer from '../Global/ModalContainer'
import TopNoticeBar from './TopNoticeBar'
import CustomizerHeader from './Headers/CustomizerHeader'
import DashboardHeader from './Headers/DashboardHeader'
import PluginNotices from './PluginNotices'
import { __ } from '@wordpress/i18n'

const Header = ( props ) => {

    const currentContext = SbUtils.getCurrentContext();

    const {
        apiKeyModal,
        noticeBarMemo,
        noticeBar,
        editorFeedData,
        apis,
        headerClasses,
        globalNotices,
        apiLimits,
        isPro,
        freeRet,
        addSModal,
        freeRetModal
    } = useContext( currentContext ) ;
    //Save Feed Customizer Settings
    const editorTopLoader = props.editorTopLoader;


    const openAddApiKeyModal = (provider) => {
        apiKeyModal.setAddApiKeyModal({
            active : true,
            provider :provider
        })
    }


    const getHeaderOutput = () => {
        switch ( props.type ) {
            //Header For The Feed Customizer Screens
            case 'customizer':
                return (
                    <CustomizerHeader
                        editorTopLoader={editorTopLoader}
                    />
                )
            default:
                return (
                    <DashboardHeader
                        heading={props.heading}
                        showHelpButton={props.showHelpButton}
                    />
                )
        }
    }

    //Print Notice Bar
    const printNoticeBar = () => {
        let outPut = null;
        //IF Not Pro Always display the upgrade
        if( noticeBarMemo !== undefined ){
            outPut = Object.keys(noticeBarMemo.topNoticeBar)?.map( ( nbr, nbKey ) => {
                nbr = noticeBarMemo.topNoticeBar[nbr];
                if( nbr !== undefined && nbr?.active === true){
                    return (
                        <TopNoticeBar
                            key={ nbKey }
                            type={ nbr?.type || 'important'}
                            heading={ nbr?.heading }
                            active={ nbr?.active }
                            actionText={ nbr?.actionText }
                            actionsList={ nbr?.actionsList }
                            close={ nbr?.close }
                            actionClick={ () => {
                                nbr?.actionClick()
                            } }
                            onClose={ () => {
                                let apiKeyNotice = {
                                    ...noticeBarMemo.topNoticeBar,
                                    apiKeyNotice : {
                                        ...noticeBarMemo.topNoticeBar.apiKeyNotice,
                                        active : false
                                    }
                                },
                                newNoticeBar = SbUtils.checkAPIKeys( noticeBarMemo, apiKeyNotice, apis, editorFeedData.feedData, apiKeyModal, apiLimits, isPro, freeRet )
                                noticeBar.setTopNoticeBar(
                                    newNoticeBar.topNoticeBar
                                )
                            }}
                            openAddApiKeyModal={(provider) => {
                                openAddApiKeyModal(provider)
                            }}
                        />
                    )
                }
            } )
        }
        return outPut
    }

    return (
        <>
            <section className={
                    (props?.className !== undefined ? props?.className : '') + ' ' +
                    (headerClasses?.headerCustomClasses !== undefined ? headerClasses?.headerCustomClasses.join(' ') : '') +
                    ' sb-header sb-fs'
                }
            >
                { printNoticeBar() }
                <div className='sb-header-content sb-fs'>
                    { getHeaderOutput() }
                </div>
                {
                    editorTopLoader?.loader &&
                    <div className='sb-loadingbar-ctn'></div>
                }
            </section>

            {
                ( globalNotices !== undefined &&
                globalNotices?.pluginNotices &&
                globalNotices?.pluginNotices.length > 0 )
                &&
                <PluginNotices/>
            }
            {
                ( apiKeyModal !== undefined && apiKeyModal.addApiKeyModal.active ) &&
                <ModalContainer
                    size='small'
                    closebutton={true}
                    onClose={ () => {
                        apiKeyModal.setAddApiKeyModal( {
                            active : false
                        } )
                    } }
                >
                    <AddApiKeyModal
                        onCancel={ () => {
                            apiKeyModal.setAddApiKeyModalActive( {
                                active : false
                            })
                        } }
                    />
                </ModalContainer>
            }
        </>

    );


}

export default Header;