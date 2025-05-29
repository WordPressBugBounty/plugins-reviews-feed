import { useContext, useState } from 'react'
import { __ } from '@wordpress/i18n'
import Button from '../Common/Button';
import SbUtils from '../../Utils/SbUtils';
import Input from '../Common/Input';

const AddApiKeyModal = ( props ) => {


    const currentContext = SbUtils.getCurrentContext();

    const {
            sbCustomizer,
            apiKeyModal,
            editorTopLoader,
            editorNotification,
            apis,
            apiLimits,
            globalNotices,
            isPro,
            freeRet
        } = useContext( currentContext );

    const [apiKeyValue, setApiKeyValue] = useState( apis?.apiKeys[apiKeyModal.addApiKeyModal.provider] || '' );
    const [ inputError, setInputError ] = useState( null ); //Will serve in case of adding Invalid API key or Wrong Place/Location URL/ID

    const updateApiKey = (isDelete = false) => {
        const formData = {
            action : 'sbr_feed_saver_manager_update_api_key',
            provider : apiKeyModal.addApiKeyModal.provider,
            apiKey : apiKeyValue,
            removeApiKey : isDelete
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data?.error !== undefined ){
                    const errorUnknown = {
                        type : 'error',
                        icon : 'notice',
                        text : __('Unknown error occured!', 'sb-customizer')
                    }
                    SbUtils.applyNotification( errorUnknown , editorNotification )
                }
                if (data?.freeRetrieverData) {
                    freeRet.setFreeRetrieverData(data?.freeRetrieverData)
                }

                if( data?.error !== undefined ){
                    processAPIKeyReturn( data?.error );
                }
                if( Array.isArray(data?.apiKeys) || typeof data?.apiKeys === 'object'){
                    apis.setApiKeys( data?.apiKeys );
                    processAPIKeyReturn( data?.apikey );
                }
                if( data?.apiKeyLimits !== undefined ){
                    const newApiLimits = [...data?.apiKeyLimits]
                    apiLimits.setApiKeyLimits( newApiLimits );
                }
                if( data?.pluginNotices !== undefined ){
                    const newPluginNotices = [...data?.pluginNotices]
                    globalNotices.setPluginNotices( newPluginNotices );
                }
            },
            editorTopLoader,
            editorNotification,
            null
        )
    }

    const processAPIKeyReturn = ( apiKeyReturn ) => {
        if( apiKeyReturn !== undefined ){
            let notificationApiKey = {};
            if( apiKeyReturn === 'valid' ){
                notificationApiKey = {
                    icon : 'success',
                    text : __('API Key Updated', 'sb-customizer')
                }
                apiKeyModal.setAddApiKeyModal({
                    active : false
                })
            }
            else if( apiKeyReturn === 'invalid' || apiKeyReturn === 'invalidKey' ){
                setReturnError( 'errorAPIKey' );
                notificationApiKey = {
                    type : 'error',
                    icon : 'notice',
                    text : __('Invalid API Key', 'sb-customizer')
                }
            }
            else if( apiKeyReturn === 'deleted' ){
                notificationApiKey = {
                    icon : 'success',
                    text : __('API Key deleted successfuly', 'sb-customizer')
                }
                setApiKeyValue('');
                setTimeout(() => {
                    apiKeyModal.setAddApiKeyModal({
                        active : false
                    })
                }, 1500);
            }
            SbUtils.applyNotification( notificationApiKey , editorNotification )
        }

    }

    const setReturnError = ( errorType ) => {
        setInputError( errorType );
        setTimeout(() => {
            setInputError( null );
        }, 3500);
    }

    const utmSource = isPro ? 'reviews-pro' : 'reviews-free'

    const getDocLink = ( provider )  => {
        const providerInfo = sbCustomizer?.providers.filter( pr => pr.type === provider );
        if( providerInfo[0]?.docLink ){
            return providerInfo[0]?.docLink + '?reviews&utm_campaign='+utmSource+'&utm_source=settings&utm_medium=create-'+provider+'-api-key&utm_content='+provider+'%20API%20Key'
        }
        return false
    }

    return (
        <div className="sb-addapikey-modal sb-fs">
            <div className="sb-addapikey-modal-heading sb-fs">
                <h4 className='sb-h4'>{ __( 'Add API Key for ', 'sb-customizer' ) } <span>{ apiKeyModal.addApiKeyModal.provider }</span></h4>
                <span className='sb-text-small sb-dark2-text sb-fs'>
                    { __( 'Currently the ', 'sb-customizer' ) }
                       <span className='sb-addapikey-modal-providername'>{ apiKeyModal.addApiKeyModal.provider }</span>
                    { __( ' API does not allow us to update your feed without you creating an app and adding an API key.', 'sb-customizer' ) }
                </span>
            </div>
            <div
                className="sb-addapikey-modal-input sb-fs"
                data-error={ inputError === 'errorAPIKey' }
            >
                <Input
                    value={ apiKeyValue }
                    size='medium'
                    onChange = { ( event ) =>
                        setApiKeyValue( event.currentTarget.value )
                    }
                    placeholder={ __( 'Enter or Paste API Key', 'sb-customizer' ) }
                />
                {
                    SbUtils.checkNotEmpty(apis?.apiKeys[apiKeyModal.addApiKeyModal.provider]) &&
                    <Button
                        type='secondary'
                        size='medium'
                        text={ __( 'Remove', 'sb-customizer' ) }
                        onClick={ () => {
                            updateApiKey(true)
                        } }
                    />
                }
                <Button
                    type='primary'
                    size='medium'
                    iconSize='12'
                    text={ SbUtils.checkNotEmpty( apiKeyValue ) ? __( 'Update', 'sb-customizer' ) :  __( 'Add', 'sb-customizer' ) }
                    icon='plus'
                    onClick={ () => {
                        updateApiKey()
                    } }
                />
            </div>
            <div className="sb-addapikey-modal-learn sb-fs">
                <Button
                    type='secondary'
                    size='medium'
                    iconSize='8'
                    text={ __( 'Learn more about how to create and add an API Key here', 'sb-customizer' ) }
                    icon='chevron-right'
                    icon-position='right'
                    full-width='true'
                    boxshadow={false}
                    link={getDocLink(apiKeyModal.addApiKeyModal.provider)}
                    target='_blank'
                />
            </div>
        </div>
    )

}

export default AddApiKeyModal;