import { useContext, useEffect, useMemo, useState } from 'react'
import { __ } from '@wordpress/i18n'
import Button from '../Common/Button';
import Source from '../Common/Source';
import AddSourceModal from './AddSourceModal';
import SbUtils from '../../Utils/SbUtils';
import FreeRetrieverModal from './FreeRetrieverModal';

const CustomizerSourcesModal = ( props ) => {
    const {
		sources,
        sourcesCount,
		editorNotification,
		sbCustomizer,
		editorTopLoader,
        addSModal,
        freeRetModal,
        apis,
        freeRet
	} = useContext( SbUtils.getCurrentContext() );

    const [ choosedSources, setChoosedSources ] = useState( props.selectedSources );
    const [ addSourcesModalActive, setAddSourcesModalActive ] = useState( false );
    const [ isLoadingSources, setIsLoadingSources ] = useState( false );
    const [ sourcesPage, setSourcesPage ] = useState(2);

    const selectSourceClick = ( sourceId ) => {
        const sSources = Object.values( choosedSources );
        if( !sSources.includes( sourceId ) ){
            sSources.push(sourceId)
        }else{
            sSources.splice( sSources.indexOf( sourceId ), 1 );
        }
        setChoosedSources(sSources)
    }

    const connectFacebookActiveMemo = useMemo( () => {
        if( props.fbModal.connectFacebookActive === true ){
            setAddSourcesModalActive( true )
            return true;
        }
        return false;
    },[ props.fbModal ]);

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

    const [onSuccessApiKey, setOnSuccessApiKey] = useState(null)
    useEffect(() => {
        if (sbCustomizer?.openSourceModal) {
            setAddSourcesModalActive( true )
        }
        const initModalScreen = SbUtils.initAddSourceModalScreen(apis, freeRet)  // addSource, freeRetriever
        addSModal.setModalType(initModalScreen)

        const initRetrModal = SbUtils.initRetrieveModalScreen(apis, freeRet) // verifyEmail - sourceAdded - limitExceeded - addApiKey
        freeRetModal.setRetModalType(initRetrModal)
    }, []);

    return (
        <>
        {
            !addSourcesModalActive &&
            <div className='sb-feedsources-modal sb-fs'>
                <div className='sb-feedsources-modal-content'>
                    <div className='sb-feedsources-modal-heading sb-fs'>
                        <h4 className='sb-h4'>{ __( 'Add Sources', 'sb-customizer' ) }</h4>
                        <span className='sb-text-small sb-dark2-text'>{ __( 'Select one or more sources you would like to add', 'sb-customizer' ) }</span>
                    </div>
                    <div className='sb-feedsources-modal-list sb-fs'>
                        <Button
                            text={ __( 'Add New', 'sb-customizer' ) }
                            icon='plus'
                            customClass='sb-addnew-source'
                            boxshadow={ false }
                            size='small'
                            iconSize='14'
                            onClick={ () => {
                                setAddSourcesModalActive( true )
                            } }
                        />
                    {
                        sources.sourcesList.map( ( source, sourceInd ) => {
                            return (
                                <Source
                                    key={ sourceInd }
                                    provider={ source.provider }
                                    checkbox={true}
                                    isChecked={ choosedSources.includes( source.account_id ) }
                                    name={ source.name }
                                    onClick={ () => {
                                        selectSourceClick( source.account_id );
                                    }}
                                />
                            )
                        } )
                    }
                    </div>
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

                    <div className='sb-feedsources-modal-btns sb-fs'>
                        <Button
                                size='medium'
                                type='secondary'
                                onClick={ () => {
                                    props?.onCancel &&
                                        props.onCancel()
                                }}
                                text={ __( 'Cancel', 'sb-customizer' ) }
                            />
                            <Button
                                icon='plus'
                                iconSize='12'
                                size='medium'
                                type='primary'
                                onClick={ () => {
                                    props.onUpdateSources( choosedSources )
                                } }
                                text={ __( 'Add', 'sb-customizer' ) }
                            />
                    </div>
                </div>
            </div>
        }
        {
            addSourcesModalActive &&
            <>
                {
                    addSModal?.modalType === 'addSource' &&
                    <AddSourceModal
                        openDialog={ () => {
                            setAddSourcesModalActive( true )
                        } }
                        onCancel={ () => {
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
            </>
        }

    </>

    )
}


export default CustomizerSourcesModal;