import { __ } from "@wordpress/i18n"
import { useContext } from "react";
import SbUtils from "../../../../Utils/SbUtils";
import SettingsScreenContext from "../../../Context/SettingsScreenContext";
import Button from "../../../Common/Button";

const ManageApiKeys = ( props ) => {
    const {
        sbCustomizer,
        apiKeyModal,
        apiLimits,
        apis,
        allowedTiers,
        freeRet
    } = useContext( SettingsScreenContext ) ;

    const freeProviderKey = freeRet?.freeRetrieverData?.providers ?? [];
    const providers = sbCustomizer?.providers.map( pr => ( (pr?.apiKey && pr?.apiKey === true) || freeProviderKey.includes(pr?.type)) && pr );

    const openApiKeyModal = ( provider ) => {
        apiKeyModal.setAddApiKeyModal({
            active : true,
            provider : provider.type
        })
    }

    return (
        <>
            <div className='sb-apikeys-list sb-fs'>
                {
                    providers?.map( provider => {
                        if( provider !== undefined && allowedTiers.tiers.includes( provider.type ) ){
                            return (
                                <div
                                    className='sb-apikeys-item'
                                    key={ provider.type }
                                    /*data-limit={ apiLimits?.apiKeyLimits.includes( provider.type ) }*/
                               >
                                    { SbUtils.printIcon( provider.type + '-provider' ) }
                                    <span className='sb-text-small sb-bold '>
                                        { provider.name }
                                    </span>
                                    {
                                        provider.type === 'google' &&
                                        apis?.apiKeys?.googleApiType === 'new' &&
                                        <Button
                                            type='primary'
                                            size='small'
                                            tooltip={ __( 'You are using the new Google Places API', 'reviews-feed' ) }
                                            text={ __( 'New API', 'reviews-feed' ).replace(/\s/g, '&nbsp;')  }
                                            customClass='sb-api-item-new-notice'
                                        />
                                    }
                                    {
                                        /*
                                        apiLimits?.apiKeyLimits.includes( provider.type ) &&
                                        <div
                                            className='sb-apikeys-item-notice'
                                            onClick={ () => {
                                                openApiKeyModal( provider )
                                            }}
                                        >
                                            { SbUtils.printIcon( 'help', false, false, 18 ) }
                                        </div>
                                        */
                                    }
                                    <div
                                        className='sb-apikeys-item-edit'
                                        onClick={ () => {
                                            openApiKeyModal( provider )
                                        }}
                                    >
                                        { SbUtils.printIcon( 'pen', false, false, 15 ) }
                                    </div>
                                </div>
                            )
                        }
                    } )
                }
            </div>
        </>
    );
}
export default ManageApiKeys;