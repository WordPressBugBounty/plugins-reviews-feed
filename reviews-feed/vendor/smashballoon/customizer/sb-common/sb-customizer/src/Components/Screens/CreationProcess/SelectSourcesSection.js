import { useContext, useEffect, useMemo, useState } from 'react'
import { __ } from '@wordpress/i18n'
import Button from '../../Common/Button';
import Source from '../../Common/Source';
import ModalContainer from '../../Global/ModalContainer';
import AddSourceModal from '../../Modals/AddSourceModal';
import SbUtils from '../../../Utils/SbUtils';
import FreeRetrieverModal from '../../Modals/FreeRetrieverModal';

const SelectSourcesSection = ( props ) => {

    const {
        sourcesCount,
        sources,
        fbModal,
        editorNotification,
        isPro,
        sbCustomizer,
        editorTopLoader,
        fbManualModal,
        addSModal,
        freeRet,
        freeRetModal,
        bulkSr,
        apis
    } = useContext( SbUtils.getCurrentContext() );

    const [ addSourcesModalActive, setAddSourcesModalActive ] = useState( false );
    const [ collapsedSources, setCollapsedSources ] = useState( [] );
    const [ isLoadingSources, setIsLoadingSources ] = useState( false );
    const [ sourcesPage, setSourcesPage ] = useState(2);



    useEffect(() => {
        if (sbCustomizer?.openSourceModal) {
            setAddSourcesModalActive( true )
        }
        const initModalScreen = SbUtils.initAddSourceModalScreen(apis, freeRet)  // addSource, freeRetriever
        addSModal.setModalType(initModalScreen)

        const initRetrModal = SbUtils.initRetrieveModalScreen(apis, freeRet) // verifyEmail - sourceAdded - limitExceeded - addApiKey
        freeRetModal.setRetModalType(initRetrModal)
    }, []);


    const connectFacebookActiveMemo = useMemo( () => {
        if( fbModal.connectFacebookActive === true){
            setAddSourcesModalActive( true )
            return true;
        }
        return false;
    },[ fbModal ]);

    const addSourcePopup = () => {
        const initModalScreen = SbUtils.initAddSourceModalScreen(apis, freeRet)  // addSource, freeRetriever
        addSModal.setModalType(initModalScreen)
        setAddSourcesModalActive( true )
    }

    const loadMoreSource = () => {
        setIsLoadingSources(true)
        //Ajax Call to get Template Settings
        const formData = {
            action : 'sbr_feed_saver_manager_load_more_sources',
            sources_page : sourcesPage
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'New sources loaded', 'sb-customizer' )
            }
        }

            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( resp ) => { //Call Back Function
                    setIsLoadingSources(false)
                    if( resp?.sourcesList !== undefined && resp?.sourcesList.length > 0 ){
                        const newSourcesList = sources?.sourcesList.concat(resp?.sourcesList);
                        sources.setSourcesList(newSourcesList);
                        sourcesCount.setSourcesNumber( resp?.sourcesCount );
                        setSourcesPage(sourcesPage + 1)
                    }
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
        )
    }


    const checkSourceHistoryFailure = (source) => {
        if (!['google','yelp'].includes(source?.provider)) {
            return false;
        }
        const sourceAccountID = source?.account_id;
        if (bulkSr?.bulkHistorySources[sourceAccountID] !== undefined) {
            return bulkSr?.bulkHistorySources[sourceAccountID]?.retry === true && bulkSr?.bulkHistorySources[sourceAccountID]?.is_done === false;
        }
        return false;
    }



    const [onSuccessApiKey, setOnSuccessApiKey] = useState(null)
    return (
        <>
            <div
                className={'sb-'+props.context+'-content sb-fs'}
                data-context={ props.context }
            >
                {
                    sources.sourcesList.length !== 0 &&
                    <div className='sb-sources-list sb-fs'>
                        <Button
                            text={ __( 'Add New', 'sb-customizer' ) }
                            icon='plus'
                            customClass='sb-addnew-source'
                            boxshadow={ false }
                            size='small'
                            iconSize='14'
                            onClick={ () => {
                                addSourcePopup()
                            } }
                        />
                        {
                            sources.sourcesList.map( ( source, sourceInd ) => {
                                return (
                                    <Source
                                        key={ sourceInd }
                                        provider={ source.provider }
                                        checkbox={props.context !== 'settingspage' && true}
                                        isChecked={props.context !== 'settingspage' && props.selectedSources.includes( source.account_id ) }
                                        name={ source.name }
                                        removeIcon={ props.context === 'settingspage' }
                                        infoIcon={ props.context === 'settingspage' }
                                        accountId={ source.account_id }
                                        editorNotification={ editorNotification }
                                        isCollapsed={ collapsedSources.includes( source.account_id )  }
                                        needHistoryCheck={ checkSourceHistoryFailure( source ) }
                                        onRemoveClick={ () => {
                                            props.context === 'settingspage' &&
                                                props.deleteSource( source );
                                        }}
                                        onClick={ () => {
                                            props.context !== 'settingspage' &&
                                                props.selectSourcesAction( source.account_id );
                                        }}
                                        onShowInfoClick={ () => {
                                            setCollapsedSources( [...SbUtils.updateArray( source.account_id,collapsedSources )] );
                                        }}


                                    />
                                )
                            } )
                        }
                    </div>
                }
                {
                    sources.sourcesList.length === 0 &&
                    <div className='sb-emptysources-notice'>
                        <span className='sb-fs sb-text-small sb-dark-text'>{ __( 'Looks like you have not added any source. Use “Add Source” to add a new one.' , 'sb-customizer') }</span>
                        <Button
                            text={ __( 'Add Source', 'sb-customizer' ) }
                            type='secondary'
                            size='medium'
                            icon='plus'
                            boxshadow={ false }
                            iconSize='14'
                            onClick={ () => {
                                addSourcePopup()
                            } }
                        />
                    </div>
                }

                {
                    //Should Show Load More Sources Button
                    <div className='sb-load-source-bottom sb-fs'>
                        <div className='sb-load-source-number sb-fs'>{__('Showing', 'reviews-feed') + ' ' + sources.sourcesList.length + ' ' + __('of', 'reviews-feed') + ' ' + sourcesCount.sourcesNumber + ' ' + __('Sources', 'reviews-feed')}</div>

                        {
                            sourcesCount.sourcesNumber > sources.sourcesList.length &&
                            <Button
                                full-width='true'
                                type='secondary'
                                size='medium'
                                icon={isLoadingSources ? 'loader' : 'loadbutton'}
                                loading={isLoadingSources}
                                text={__('Load More', 'reviews-feed')}
                                onClick={() => {
                                    loadMoreSource()
                                }}
                            />
                        }
                    </div>
                }
            </div>
            {
                addSourcesModalActive &&
                <ModalContainer
                    size={SbUtils.getAddSourceModalSize(addSModal?.modalType === 'freeRetriever')}
                    closebutton={true}
                    onClose={ () => {
                        if (
                            addSModal?.modalType === 'freeRetriever'
                            && freeRetModal?.retModalType === 'verifyEmail'
                            && !SbUtils.checkAPIKeyExists('google', apis)
                            && !SbUtils.checkAPIKeyExists('yelp', apis)
                        ) {
                            setAddSourcesModalActive( false )
                        } else {
                            if (addSModal?.modalType !== 'freeRetriever'){
                                setAddSourcesModalActive( false )
                            }
                            freeRetModal.setRetModalType(SbUtils.initRetrieveModalScreen(apis, freeRet))
                            addSModal.setModalType('addSource')
                        }
                        setOnSuccessApiKey(null)

                    } }
                >
                    {
                        addSModal?.modalType === 'addSource' &&
                        <AddSourceModal
                            openDialog={ () => {
                                setAddSourcesModalActive( true )
                            } }
                            onCancel={ () => {
                                setOnSuccessApiKey(null)
                                setAddSourcesModalActive( false )
                            } }
                            onSuccessApiKey={onSuccessApiKey}
                        />
                    }
                    {
                       addSModal?.modalType === 'freeRetriever' &&
                        <FreeRetrieverModal
                            screenType={freeRetModal?.retModalType} // - verifyEmail - sourceAdded - limitExceeded - addApiKey
                            openDialog={ () => {
                                setAddSourcesModalActive( true )
                            } }
                            onCancel={ () => {
                                setAddSourcesModalActive( false )
                                setOnSuccessApiKey(null)
                            } }
                            onSuccessApiKey={(provider) => {
                                setOnSuccessApiKey(provider)
                            }}
                        />
                    }
                </ModalContainer>
            }
        </>
    )
}

export default SelectSourcesSection;