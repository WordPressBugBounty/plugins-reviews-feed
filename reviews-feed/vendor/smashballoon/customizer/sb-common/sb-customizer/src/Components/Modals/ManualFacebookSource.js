import { __ } from "@wordpress/i18n";
import { useContext, useMemo, useState } from "react";
import Button from "../Common/Button";
import Input from "../Common/Input";
import ModalContainer from "../Global/ModalContainer";
import SbUtils from '../../Utils/SbUtils'
import ToggleSet from "../Common/ToggleSet";
import Notice from "../Global/Notice";

const ManualFacebookSource = () => {
    const [ errorConnect, setErrorConnect ] = useState( false );
    const [ fbPageId, setFbPageId ] = useState( '' );
    const [ fbPageToken, setFbPageToken ] = useState( '' );
    const [ fbPageType, setFbPageType ] = useState( 'page' );
    const {
        fbManualModal,
        editorTopLoader,
        editorNotification,
        sbCustomizer,
        sources
    } = useContext( SbUtils.getCurrentContext() )

    const fbSourceTypes = [
        {
            'value' : 'page',
            'label' : __( 'Page', 'sb-customizer' )
        },
        {
            'value' : 'group',
            'label' : __( 'Group', 'sb-customizer' )
        }
    ];


    const checkNotEmptyFbInfo = useMemo( () => {
        return SbUtils.checkNotEmpty( fbPageId ) && SbUtils.checkNotEmpty( fbPageToken )
    } ,  [ fbPageId, fbPageToken] )

    const connectManualAccount = () => {
        setErrorConnect( false )
        if( checkNotEmptyFbInfo ){
            //Ajax Call to get Template Settings
            const formData = {
                action : 'sbr_feed_saver_manager_connect_manual_facebook',
                pageType : fbPageType,
                pageId : fbPageId,
                pageToken : fbPageToken
            },
            notificationsContent = {
                success : {
                    icon : 'success',
                    text : __( 'Source Added Successfully', 'sb-customizer' )
                },
                error : {
                    type : 'error',
                    icon : 'error',
                    text : __( 'Something went wrong', 'sb-customizer' )
                }
            }

            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( resp ) => { //Call Back Function
                    let data = resp?.data;
                    if( data?.sourcesList !== undefined ){
                        sources.setSourcesList( data?.sourcesList );
                        SbUtils.applyNotification( notificationsContent.success , editorNotification )
                        setTimeout(() => {
                            setFbPageId('')
                            setFbPageToken('')
                            fbManualModal.setConnectFacebookManualActive(null)
                        }, 1000);
                    }
                    if( data?.error !== undefined ){
                       setErrorConnect( true )
                    }
                },
                editorTopLoader,
                null,
                null
            )
        }
    }


    return (
        <ModalContainer
            size='medium'
            closebutton={true}
            onClose={ () => {
                fbManualModal.setConnectFacebookManualActive(null)
            } }
        >
            <div className="sb-addsource-modal sb-fbaddsource-modal sb-fs">
                <div className="sb-addsource-modal-heading sb-fs">
                    <h4 className='sb-h4'>{ __( 'Add a Source Manually', 'sb-customizer' ) }</h4>
                </div>
                <div className="sb-fbaddsource-form-modal sb-fs">
                    <div className="sb-fbaddsource-type-modal sb-fs">
                        <span className="sb-text-tiny sb-dark2-text sb-fs">{ __( 'Source Type', 'sb-customizer' ) }</span>
                        <div className="sb-fbaddsource-sourcetype-modal sb-fs">
                            <ToggleSet
                                customClass=''
                                layout='list'
                                options={ fbSourceTypes }
                                value={ fbPageType }
                                onClick={ ( pageID ) => {
                                        setFbPageType(pageID)
                                } }
                            />
                        </div>
                    </div>
                    <div className="sb-fbaddsource-inputs-modal sb-fs">
                        <span className="sb-text-tiny sb-light-text sb-fs">{ __( 'Facebook Page or Group ID', 'sb-customizer' ) }</span>
                        <Input
                            size='medium'
                            customClass='sb-fs'
                            placeholder={ __( 'Enter ID', 'sb-customizer' ) }
                            value={fbPageId}
                            onChange={ ( event ) => {
                                setFbPageId( event.currentTarget.value )
                            } }
                        />
                        <span className="sb-text-tiny sb-light-text sb-fs">{ __( 'Facebook Access Token', 'sb-customizer' ) }</span>
                        <Input
                            size='medium'
                            customClass='sb-fs'
                            placeholder={ __( 'Enter Token', 'sb-customizer' ) }
                            value={fbPageToken}
                            onChange={ ( event ) => {
                                setFbPageToken( event.currentTarget.value )
                            } }
                        />
                        {
                            errorConnect === true &&
                            <div className="sb-fbaddsource-notice-modal sb-fs">
                                {
                                    <Notice
                                        icon='info'
                                        heading={ __( 'There was a problem connecting this account. Please make sure your access token and ID are correct.', 'sb-customizer' ) }
                                        text={ __( 'API Response: Invalid OAuth access token - Cannot parse access token', 'sb-customizer' ) }
                                    />
                                }
                            </div>
                        }
                    </div>
                    <div className="sb-fbaddsource-btn-modal sb-fs">
                        <Button
                            type={ checkNotEmptyFbInfo ? 'primary' : 'secondary' }
                            disabled={checkNotEmptyFbInfo !== true}
                            size='medium'
                            full-width='true'
                            text={ __( 'Add', 'sb-customizer' ) }
                            icon='success'
                            onClick={ () => {
                                connectManualAccount()
                            } }
                        />
                    </div>
                </div>
            </div>

        </ModalContainer>
    )
}

export default ManualFacebookSource;